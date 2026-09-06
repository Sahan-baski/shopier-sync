// Bu dosya Shopier ile konusan TEK yer. Boylece gercek endpoint/alan adlari netlesince
// sadece burasi degisecek, stockEngine.js ve webhook.js'e dokunmaya gerek kalmayacak.
//
// ARTIK TAMAMEN NETLESTI (developer.shopier.com'un tam OpenAPI semasi Ahmet tarafindan
// "Copy Page" ile getirildi):
//   - Taban adres: https://api.shopier.com/v1/
//   - Kimlik dogrulama: Authorization: Bearer <Kisisel Erisim Anahtari> (bearerAuth)
//   - GET /webhooks, POST /webhooks, DELETE /webhooks/{id} -> webhook aboneligi yonetimi
//   - GET /products/{id} -> tek bir urunun TUM varyasyonlarini (variants[]) doner. Her
//     varyasyonda: selectionId (array, bizde tek elemanli - sadece "beden" boyutu var),
//     selectionTitle (array, ayni sirada - ornegin ["3-4 Yaş"]), stockQuantity.
//     ONEMLI: Bu sayede varyasyon ID'lerini artik "İncele" ile elle bulmaya HIC gerek yok -
//     urun id'sini bilince, Shopier'in kendisinden dogru ID+baslik eslesmesini cekebiliyoruz.
//   - PUT /products/{id} -> urunu gunceller. Govdede "variants" alani gonderilirse urunun
//     TUM varyasyon listesini degistirir - bu yuzden stok guncellerken ONCE GET ile mevcut
//     TUM varyasyonlari cekip, SADECE hedef varyasyonun stockQuantity'sini degistirip,
//     TUM listeyi geri PUT ediyoruz (yoksa diger bedenlerin varyasyonlarini kaybederiz).
//   - Webhook nesnesi: { id, event, url, token } - token SADECE olusturma cevabinda bir kez donuyor
//
// DRY_RUN=true iken sistem CANLI Shopier'e hicbir yazma istegi atmaz (GET/okuma istekleri
// haric - varyasyon listesini cekmek her zaman calisir, sadece PUT ile yazma engellenir).

const DRY_RUN = String(process.env.DRY_RUN || 'true').toLowerCase() !== 'false';
const API_BASE = process.env.SHOPIER_API_BASE || 'https://api.shopier.com/v1';
const ACCESS_KEY = process.env.SHOPIER_ACCESS_KEY || '';

function authHeaders() {
  return {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${ACCESS_KEY}`,
  };
}

/**
 * Bir urunun Shopier'daki GUNCEL halini (tum varyasyonlariyla) ceker.
 * DRY_RUN'dan BAGIMSIZ calisir - bu sadece okuma, hicbir sey degistirmiyor.
 * Donen: { raw: <ham Shopier urun objesi>, variants: [{selectionId, selectionTitle, stockQuantity}] }
 * NOT: selectionId/selectionTitle Shopier'da array olarak geliyor (coklu secim boyutu
 * icin) - bizde tek boyut (beden) oldugu icin ilk elemani aliyoruz.
 */
async function fetchProduct(shopierProductId) {
  if (!ACCESS_KEY) throw new Error('SHOPIER_ACCESS_KEY tanimli degil (.env dosyasina gir).');
  if (!shopierProductId) throw new Error('shopierProductId gerekli.');

  const url = `${API_BASE}/products/${encodeURIComponent(shopierProductId)}`;
  const res = await fetch(url, { headers: authHeaders() });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`Shopier urun okuma hatasi (${res.status}): ${text}`);
  }
  const raw = await res.json();
  const variants = (raw.variants || []).map((v) => ({
    selectionId: Array.isArray(v.selectionId) ? v.selectionId[0] : v.selectionId,
    selectionTitle: Array.isArray(v.selectionTitle) ? v.selectionTitle[0] : v.selectionTitle,
    stockQuantity: v.stockQuantity,
  }));
  return { raw, variants };
}

/**
 * ONEMLI KESIF (06.09.2026): GET/PUT /products/{id} bu hesapta 403 Forbidden donuyor
 * (hem Node fetch hem raw curl ile dogrulandi - Shopier destegine bildirilecek), FAKAT
 * /selections, /variations ve /orders ayni PAT ile calisiyor. Gercek siparis gecmisi
 * incelendiginde, "Çocuk Tişört Beden" varyasyonu altindaki secenek (selection) ID'lerinin
 * TUM tasarimlar arasinda ORTAK/GLOBAL oldugu dogrulandi (ayni "3-4 Yaş" ID'si 6 farkli
 * urunde ayni cikti). Yani beden ID'lerini urune ozel GET /products/{id} yerine, hesap
 * genelindeki /selections listesinden BIR KERE cekip her tasarima uygulayabiliriz.
 *
 * fetchAllSelections/fetchAllVariations: sayfalama header'larini (shopier-pagination-*)
 * takip ederek TUM sayfalari gezip birlestirir - Shopier'in limit parametresini
 * yoksaymasi ihtimaline karsi da guvenli calisir.
 */
async function fetchAllSelections() {
  if (!ACCESS_KEY) throw new Error('SHOPIER_ACCESS_KEY tanimli degil (.env dosyasina gir).');
  const all = [];
  let page = 1;
  for (;;) {
    const url = `${API_BASE}/selections?page=${page}&limit=50`;
    const res = await fetch(url, { headers: authHeaders() });
    if (!res.ok) {
      const text = await res.text().catch(() => '');
      throw new Error(`Shopier /selections hatasi (${res.status}): ${text}`);
    }
    const data = await res.json();
    all.push(...data);
    const totalPages = Number(res.headers.get('shopier-pagination-total-pages') || 1);
    if (!data.length || page >= totalPages) break;
    page++;
  }
  return all;
}

async function fetchAllVariations() {
  if (!ACCESS_KEY) throw new Error('SHOPIER_ACCESS_KEY tanimli degil (.env dosyasina gir).');
  const all = [];
  let page = 1;
  for (;;) {
    const url = `${API_BASE}/variations?page=${page}&limit=50`;
    const res = await fetch(url, { headers: authHeaders() });
    if (!res.ok) {
      const text = await res.text().catch(() => '');
      throw new Error(`Shopier /variations hatasi (${res.status}): ${text}`);
    }
    const data = await res.json();
    all.push(...data);
    const totalPages = Number(res.headers.get('shopier-pagination-total-pages') || 1);
    if (!data.length || page >= totalPages) break;
    page++;
  }
  return all;
}

/**
 * "Beden" iceren TUM varyasyon boyutlarinin (ör. "Çocuk Tişört Beden", "Çocuk Beden")
 * altindaki secenekleri (3-4 Yaş, 5-6 Yaş, ...) tek listede doner. Urun ID'sine ihtiyac
 * YOK - bu liste hesap genelinde ortak. Donen her eleman: {selectionId, selectionTitle, variationId, variationTitle}.
 */
async function fetchChildSizeSelections() {
  const [selections, variations] = await Promise.all([fetchAllSelections(), fetchAllVariations()]);
  const sizeVariations = variations.filter((v) => /beden/i.test(v.title || ''));
  const sizeVariationIds = new Set(sizeVariations.map((v) => v.id));
  const titleById = new Map(sizeVariations.map((v) => [v.id, v.title]));

  return selections
    .filter((s) => sizeVariationIds.has(s.variationId))
    .map((s) => ({
      selectionId: s.id,
      selectionTitle: s.title,
      variationId: s.variationId,
      variationTitle: titleById.get(s.variationId) || '',
    }));
}

/**
 * Shopier'den gelen siparis webhook govdesini {shopierProductId, shopierVariantId, qty, orderRef} listesine cevirir.
 * NETLESTI (gercek "order.created" webhook'undan alinan ornek ile): govde su sekilde geliyor:
 *   { id: "784630780", lineItems: [
 *       { productId: "50115452", quantity: 1,
 *         selection: [ { id: "097613d2b7a41249", title: "3-4 Yaş", variationTitle: "Çocuk Tişört Beden" } ] }
 *   ], ... }
 * Yani: siparis id'si -> body.id, urun id'si -> lineItems[].productId,
 * beden/varyasyon id'si -> lineItems[].selection[0].id (adminde "Bağla" ile girilen Varyasyon ID budur),
 * adet -> lineItems[].quantity (selection basina degil, satir basina).
 * Birden fazla secim boyutu olan urunler icin (bizde olmuyor ama garanti olsun diye)
 * tum selection id'leri '|' ile birlestiriliyor.
 */
function parseIncomingOrder(body) {
  const orderRef = body.id || body.order_id || body.orderId || null;
  const lineItems = body.lineItems || body.line_items || body.items || [];

  const results = [];
  for (const item of lineItems) {
    const productId = String(item.productId ?? item.product_id ?? '');
    const qty = Number(item.quantity ?? item.qty ?? 1);
    const selections = item.selection || item.selections || [];

    if (!selections.length) continue; // bedeni olmayan/varyasyonsuz urun - bizim sistemde yonetilmiyor

    const variantId = selections.map((s) => String(s.id ?? '')).join('|');
    results.push({ shopierProductId: productId, shopierVariantId: variantId, qty, orderRef });
  }
  return results;
}

/**
 * Tek bir varyasyonun (selection) stogunu Shopier'da gercek deger ile esitler.
 * NETLESTI: Shopier'da ayri bir "stok guncelleme" endpoint'i yok - PUT /products/{id}
 * ile urunun TUM varyasyon listesi gonderiliyor. Bu yuzden:
 *   1) Once GET ile urunun GUNCEL tum varyasyonlarini cekiyoruz (baska bir yerden - ornegin
 *      Shopier panelinden elle - degismis olabilecek diger varyasyonlari EZMEMEK icin).
 *   2) Sadece hedef selectionId'nin stockQuantity'sini degistiriyoruz.
 *   3) TUM varyasyon listesini (digerleri aynen, hedef degismis) geri PUT ediyoruz.
 * shopierProductId + shopierVariantId (selectionId) ikisi de sart.
 */
async function pushVariantStock({ shopierProductId, shopierVariantId, newStock }) {
  if (!shopierProductId) {
    return { ok: false, skipped: true, reason: 'shopier_product_id eslesmesi yok (admin panelden tanimla)' };
  }
  if (!shopierVariantId) {
    return { ok: false, skipped: true, reason: 'shopier_variant_id (selection id) eslesmesi yok (admin panelden tanimla)' };
  }

  if (DRY_RUN) {
    console.log(
      `[DRY_RUN] Shopier urun ${shopierProductId} / varyasyon ${shopierVariantId} stogu ${newStock} olarak guncellenecekti.`
    );
    return { ok: true, dryRun: true };
  }

  if (!ACCESS_KEY) {
    throw new Error('SHOPIER_ACCESS_KEY tanimli degil. .env dosyasini doldurmadan DRY_RUN=false yapma.');
  }

  const { variants } = await fetchProduct(shopierProductId);
  const target = variants.find((v) => String(v.selectionId) === String(shopierVariantId));
  if (!target) {
    throw new Error(
      `Shopier urun ${shopierProductId} icinde selectionId=${shopierVariantId} olan bir varyasyon bulunamadi - eslestirme yanlis olabilir.`
    );
  }

  const updatedVariants = variants.map((v) => ({
    selectionId: [v.selectionId],
    stockQuantity: String(v.selectionId) === String(shopierVariantId) ? Math.max(0, Number(newStock) || 0) : v.stockQuantity,
  }));

  const url = `${API_BASE}/products/${encodeURIComponent(shopierProductId)}`;
  const res = await fetch(url, {
    method: 'PUT',
    headers: authHeaders(),
    body: JSON.stringify({ variants: updatedVariants }),
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

module.exports = {
  parseIncomingOrder,
  pushVariantStock,
  verifyWebhookSignature,
  fetchProduct,
  fetchAllSelections,
  fetchAllVariations,
  fetchChildSizeSelections,
  DRY_RUN,
};
