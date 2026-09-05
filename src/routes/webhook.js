const express = require('express');
const fs = require('fs');
const path = require('path');
const { parseIncomingOrder, pushVariantStock, verifyWebhookSignature } = require('../shopierClient');
const engine = require('../stockEngine');

const router = express.Router();
const DEBUG_LOG = path.join(__dirname, '..', '..', 'data', 'webhook-debug.log');

function logRawPayload(body) {
  try {
    fs.appendFileSync(
      DEBUG_LOG,
      `\n----- ${new Date().toISOString()} -----\n${JSON.stringify(body, null, 2)}\n`
    );
  } catch (e) {
    console.error('webhook-debug.log yazilamadi:', e.message);
  }
}

// Shopier'in "OSB" (eski/legacy) sistemi bazen GET ile, parametreleri URL'nin
// sonuna ekleyerek gonderiyor olabilir. Ne gelirse gelsin yakalayip loglayalim ki
// Shopier'in hangi yontemi/alan adlarini kullandigini gorelim - test butonu
// "basarisiz" dese bile buraya bir istek dusup dusmedigine data/webhook-debug.log'dan bakilabilir.
router.get('/shopier-order', (req, res) => {
  logRawPayload({ method: 'GET', query: req.query, headers: req.headers });
  res.status(200).send('OK');
});

// Shopier'in siparis oldugunda cagiracagi adres: POST /webhook/shopier-order
// Shopier panelinde/webhook ayarlarinda bu servisin genel adresi + bu yol tanimlanmali.
// Hem JSON hem form-encoded (eski sistemler icin) govdeyi kabul ediyoruz.
// ONEMLI: Shopier'in kendi dokumantasyonuna gore webhook'a 5 SANIYE icinde 200 OK
// donmezsek, bildirim basarisiz sayilip 1dk/10dk/1sa/... seklinde 9 kez tekrar
// deneniyor. Bu yuzden: once HIZLI olan yerel hesaplamayi (SQLite) yapip HEMEN
// 200 donuyoruz, Shopier'e GERI YAZMA (pushVariantStock - yavas olabilen dis istekler)
// islemini cevaptan SONRA, arka planda yapiyoruz. Boylece yavas bir Shopier isteği
// yuzunden bildirimin "basarisiz" sayilip gereksiz tekrar denemeye girmesini onluyoruz.
router.post(
  '/shopier-order',
  express.json({ limit: '1mb', verify: (req, res, buf) => { req.rawBody = buf; } }),
  express.urlencoded({ extended: true, limit: '1mb' }),
  (req, res) => {
  // ILK YAPILACAK SEY: gelen HER siparisi ham haliyle kaydet. Ilk gercek siparis
  // geldiginde data/webhook-debug.log dosyasini acip shopierClient.parseIncomingOrder'i
  // gercek alan adlarina gore kesinlestirecegiz.
  logRawPayload({ body: req.body, query: req.query });

  if (!verifyWebhookSignature(req)) {
    return res.status(401).json({ ok: false, error: 'imza dogrulanamadi' });
  }

  let items;
  try {
    items = parseIncomingOrder(req.body);
  } catch (e) {
    console.error('Siparis govdesi cozumlenemedi:', e.message);
    return res.status(200).json({ ok: false, error: 'govde cozumlenemedi, debug log kontrol edilsin' });
  }

  // 1. asama: SADECE yerel (SQLite) hesaplamayi yap - bu cok hizli, milisaniyeler surer.
  const localResults = [];
  const cellsToPush = [];
  for (const item of items) {
    const design = engine.findDesignByShopierProductId(item.shopierProductId);
    if (!design) {
      localResults.push({ item, ok: false, reason: 'shopier_product_id eslesen tasarim yok - admin panelden eslestir' });
      continue;
    }

    // Bu urun/varyasyon hangi bedene karsilik geliyor? design_variants tablosundan bul.
    const mapping = require('../db')
      .db.prepare('SELECT size_key FROM design_variants WHERE design_id = ? AND shopier_variant_id = ?')
      .get(design.id, item.shopierVariantId);

    if (!mapping) {
      localResults.push({ item, ok: false, reason: 'varyasyon->beden eslesmesi yok - admin panelden eslestir' });
      continue;
    }

    try {
      // Satista stok DUSER, bu yuzden negatif isaretle.
      const outcome = engine.applyDelta(design.id, mapping.size_key, -1 * Math.abs(Number(item.qty) || 1), {
        orderRef: item.orderRef,
        rawPayload: item,
      });

      if (outcome.skipped) {
        localResults.push({ item, ok: false, reason: outcome.reason });
        continue;
      }

      localResults.push({ item, ok: true });
      cellsToPush.push(...outcome.cellsToPush);
    } catch (e) {
      localResults.push({ item, ok: false, reason: e.message });
    }
  }

  // 2. asama: Shopier'e HEMEN cevap ver - stok zaten dogru sekilde bizim tarafta
  // guncellendi, Shopier'i bundan haberdar etmek icin bekletmeye gerek yok.
  res.json({ ok: true, processed: localResults.length, results: localResults });

  // 3. asama: cevaptan SONRA, arka planda Shopier'e geri yaz (etkilenen tum hucreler).
  // Burada bir hata olsa bile webhook cevabini etkilemez - sadece loglanir.
  (async () => {
    for (const cell of cellsToPush) {
      const variantMapping = engine.getVariantMapping(cell.designId, cell.sizeKey);
      const shopierVariantId = variantMapping && variantMapping.shopier_variant_id;
      try {
        await pushVariantStock({ shopierVariantId, newStock: cell.effectiveStock });
      } catch (e) {
        console.error('Webhook sonrasi Shopier push hatasi:', cell, e.message);
      }
    }
  })();
});

module.exports = router;
