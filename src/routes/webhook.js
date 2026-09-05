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

// Shopier'in siparis oldugunda cagiracagi adres: POST /webhook/shopier-order
// Shopier panelinde/webhook ayarlarinda bu servisin genel adresi + bu yol tanimlanmali.
router.post('/shopier-order', express.json({ limit: '1mb' }), async (req, res) => {
  // ILK YAPILACAK SEY: gelen HER siparisi ham haliyle kaydet. Ilk gercek siparis
  // geldiginde data/webhook-debug.log dosyasini acip shopierClient.parseIncomingOrder'i
  // gercek alan adlarina gore kesinlestirecegiz.
  logRawPayload(req.body);

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

  const results = [];
  for (const item of items) {
    const design = engine.findDesignByShopierProductId(item.shopierProductId);
    if (!design) {
      results.push({ item, ok: false, reason: 'shopier_product_id eslesen tasarim yok - admin panelden eslestir' });
      continue;
    }

    // Bu urun/varyasyon hangi bedene karsilik geliyor? design_variants tablosundan bul.
    const mapping = require('../db')
      .db.prepare('SELECT size_key FROM design_variants WHERE design_id = ? AND shopier_variant_id = ?')
      .get(design.id, item.shopierVariantId);

    if (!mapping) {
      results.push({ item, ok: false, reason: 'varyasyon->beden eslesmesi yok - admin panelden eslestir' });
      continue;
    }

    try {
      // Satista stok DUSER, bu yuzden negatif isaretle.
      const outcome = engine.applyDelta(design.id, mapping.size_key, -1 * Math.abs(Number(item.qty) || 1), {
        orderRef: item.orderRef,
        rawPayload: item,
      });

      if (outcome.skipped) {
        results.push({ item, ok: false, reason: outcome.reason });
        continue;
      }

      // Etkilenen tum hucreleri Shopier'e geri yaz (ayni tablodaki tum tasarimlar dahil)
      for (const cell of outcome.cellsToPush) {
        const variantMapping = engine.getVariantMapping(cell.designId, cell.sizeKey);
        const shopierVariantId = variantMapping && variantMapping.shopier_variant_id;
        const pushResult = await pushVariantStock({
          shopierVariantId,
          newStock: cell.effectiveStock,
        }).catch((e) => ({ ok: false, error: e.message }));
        results.push({ cell, pushResult });
      }
    } catch (e) {
      results.push({ item, ok: false, reason: e.message });
    }
  }

  res.json({ ok: true, processed: results.length, results });
});

module.exports = router;
