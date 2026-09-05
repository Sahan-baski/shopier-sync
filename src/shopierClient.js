// Bu dosya Shopier ile konusan TEK yer. Boylece gercek endpoint/alan adlari netlesince
// sadece burasi degisecek, stockEngine.js ve webhook.js'e dokunmaya gerek kalmayacak.
//
// SU AN NETLESMEYEN 2 SEY (Ahmet'in Shopier developer portalindan teyit etmesi lazim):
//   1) Siparis webhook'unun (Otomatik Siparis Bildirimi / Webhooks) tam JSON semasi
//      -> parseIncomingOrder() icindeki alan adlarini o zaman kesinlestiririz.
//   2) Bir varyasyonun stogunu API ile guncelleme endpoint'i + govde semasi
//      -> pushVariantStock() icindeki TODO'yu o zaman kesinlestiririz.
// Bu ikisi netlesene kadar DRY_RUN=true birakilirsa sistem CANLI Shopier'e hicbir
// istek atmaz, sadece ne yapacagini loglar - is mantigini test etmek icin yeterli.

const DRY_RUN = String(process.env.DRY_RUN || 'true').toLowerCase() !== 'false';
const API_BASE = process.env.SHOPIER_API_BASE || 'https://api.shopier.com';
const STOCK_UPDATE_PATH = process.env.SHOPIER_STOCK_UPDATE_PATH || '';
const ACCESS_KEY = process.env.SHOPIER_ACCESS_KEY || '';

/**
 * Shopier'den gelen siparis webhook govdesini {shopierProductId, shopierVariantId, qty, orderRef} listesine cevirir.
 * NOT: Alan adlari (product_id, variant_id, quantity ...) TAHMINI - gercek payload'i gorunce
 * duzeltilmesi gerekiyor. Bu yuzden webhook.js, ham govdeyi HER ZAMAN data/webhook-debug.log'a
 * yaziyor: ilk gercek siparis geldiginde o dosyayi acip buradaki eslemeyi tek seferde kesinlestiririz.
 */
function parseIncomingOrder(body) {
  // Yaygin e-ticaret webhook sekillerine gore en olasi yapi varsayildi.
  const items = body.items || body.products || body.line_items || [];
  const orderRef = body.order_id || body.orderId || body.order_number || null;

  return items.map((item) => ({
    shopierProductId: String(item.product_id ?? item.productId ?? ''),
    shopierVariantId: String(item.variant_id ?? item.variantId ?? item.variation_id ?? ''),
    qty: Number(item.quantity ?? item.qty ?? 1),
    orderRef,
  }));
}

/**
 * Tek bir varyasyonun stogunu Shopier'da gercek deger ile esitler.
 * TODO: STOCK_UPDATE_PATH ve govde (body) semasi developer portaldan teyit edilince doldurulacak.
 */
async function pushVariantStock({ shopierVariantId, newStock }) {
  if (!shopierVariantId) {
    return { ok: false, skipped: true, reason: 'shopier_variant_id eslesmesi yok (admin panelden tanimla)' };
  }

  if (DRY_RUN) {
    console.log(`[DRY_RUN] Shopier varyasyon ${shopierVariantId} stogu ${newStock} olarak guncellenecekti.`);
    return { ok: true, dryRun: true };
  }

  if (!ACCESS_KEY || !STOCK_UPDATE_PATH) {
    throw new Error(
      'SHOPIER_ACCESS_KEY veya SHOPIER_STOCK_UPDATE_PATH tanimli degil. .env dosyasini doldurmadan DRY_RUN=false yapma.'
    );
  }

  const url = `${API_BASE}${STOCK_UPDATE_PATH}`;
  const res = await fetch(url, {
    method: 'POST', // TODO: gercek metod PATCH/PUT olabilir, portaldan teyit et
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${ACCESS_KEY}`, // TODO: gercek auth header formatini teyit et
    },
    body: JSON.stringify({
      variant_id: shopierVariantId, // TODO: gercek alan adini teyit et
      stock: newStock, // TODO: gercek alan adini teyit et
    }),
  });

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`Shopier stok guncelleme hatasi (${res.status}): ${text}`);
  }
  return { ok: true, dryRun: false };
}

/**
 * Webhook'un gercekten Shopier'den geldigini dogrulamak icin imza kontrolu.
 * Shopier dokumantasyonuna gore: her webhook govdesi (payload), webhook aboneligi
 * OLUSTURULURKEN bir kereye mahsus donen "webhook token"i kullanilarak HS256
 * (HMAC-SHA256) ile imzalaniyor ve sonuc "Shopier-Signature" header'inda geliyor.
 * O token'i SHOPIER_ORDER_WEBHOOK_SECRET olarak .env'e girince dogrulama devreye girer.
 *
 * NOT: imzanin hex mi yoksa base64 mi encode edildigi dokumantasyonda acikca
 * yazmiyordu - burada once hex deniyoruz, tutmazsa (gercek bir bildirimde surekli
 * "dogrulanamadi" hatasi alirsak) base64'e ceviririz. req.rawBody, webhook.js'te
 * express.json() 'verify' callback'i ile dolduruluyor (JSON.stringify ile yeniden
 * uretilen govde degil, Shopier'in gonderdigi TAM ham bayt dizisi olmasi sart -
 * aksi halde imza hicbir zaman tutmaz).
 */
function verifyWebhookSignature(req) {
  const secret = process.env.SHOPIER_ORDER_WEBHOOK_SECRET;
  if (!secret) return true; // token henuz .env'e girilmedi - simdilik dogrulamayi atla

  const signatureHeader = req.headers['shopier-signature'] || req.headers['x-shopier-signature'];
  if (!signatureHeader) return false; // secret tanimli ama Shopier imza gondermemis - suphelİ

  if (!req.rawBody) return false; // ham govde yakalanamadiysa dogrulama yapilamaz

  const crypto = require('crypto');
  const computedHex = crypto.createHmac('sha256', secret).update(req.rawBody).digest('hex');
  const computedBase64 = crypto.createHmac('sha256', secret).update(req.rawBody).digest('base64');

  const provided = String(signatureHeader).trim();
  return provided === computedHex || provided === computedBase64;
}

module.exports = { parseIncomingOrder, pushVariantStock, verifyWebhookSignature, DRY_RUN };
