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
 * (Opsiyonel) Webhook'un gercekten Shopier'den geldigini dogrulamak icin imza kontrolu.
 * Shopier'in imzalama yontemi netlesene kadar SHOPIER_ORDER_WEBHOOK_SECRET bossa dogrulama atlanir.
 */
function verifyWebhookSignature(req) {
  const secret = process.env.SHOPIER_ORDER_WEBHOOK_SECRET;
  if (!secret) return true; // henuz netlesmedi - simdilik gecir
  // TODO: gercek imza header'i ve algoritmasi netlesince burada dogrulama yapilacak.
  return true;
}

module.exports = { parseIncomingOrder, pushVariantStock, verifyWebhookSignature, DRY_RUN };
