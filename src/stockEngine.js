// Butun sistemin kalbi burasi. Shopier'e hic karismadan, sadece "bir satis oldu"
// bilgisinden yola cikarak hangi (tasarim, beden) hucrelerinin yeni stok degerinin
// ne olmasi gerektigini hesaplar.
//
// v2: WooCommerce eklentisiyle AYNI mimari. Istedigin kadar bagimsiz "tablo" (pool)
// olabilir (or. "Cocuk Tisort Bedenleri", "Sweatshirt Bedenleri"). Her tasarim EN FAZLA
// bir tabloya baglanir. Iki sayac (tablo bazinda):
//   - Tablonun ortak beden stogu: pool_sizes.shared_stock (o tabloya bagli TUM tasarimlar paylasir)
//   - Tasarim stogu: designs.design_stock (tasarima ozel, tablodan/bedenden bagimsiz)
// Gorunen/gercek stok = min(tablonun o bedendeki ortak stogu, tasarimin kendi stogu)

const { db } = require('./db');

// ---------------------------------------------------------------------
// TABLO (POOL) YONETIMI
// ---------------------------------------------------------------------

function getPools() {
  const pools = db.prepare('SELECT * FROM pools ORDER BY id').all();
  return pools.map((p) => ({ ...p, sizes: getPoolSizes(p.id) }));
}

function getPool(poolId) {
  const pool = db.prepare('SELECT * FROM pools WHERE id = ?').get(poolId);
  if (!pool) return null;
  return { ...pool, sizes: getPoolSizes(poolId) };
}

function getPoolSizes(poolId) {
  return db.prepare('SELECT * FROM pool_sizes WHERE pool_id = ? ORDER BY sort_order').all(poolId);
}

function cleanSizeKeys(sizeKeys) {
  const clean = [];
  for (let k of sizeKeys) {
    k = String(k).trim();
    if (k && !clean.includes(k)) clean.push(k);
  }
  return clean;
}

function parseSizeList(csv) {
  return cleanSizeKeys(String(csv || '').split(','));
}

// sizeKeys: ['3-4','5-6',...] - her biri 0 stok ile baslar. Yeni tablonun id'sini dondurur.
function createPool(name, sizeKeys) {
  name = (name || '').trim() || 'Adsız Tablo';
  sizeKeys = cleanSizeKeys(sizeKeys);

  const tx = db.transaction(() => {
    const info = db.prepare('INSERT INTO pools (name) VALUES (?)').run(name);
    const poolId = info.lastInsertRowid;
    const insertSize = db.prepare(
      'INSERT INTO pool_sizes (pool_id, size_key, sort_order, shared_stock) VALUES (?,?,?,0)'
    );
    sizeKeys.forEach((key, i) => insertSize.run(poolId, key, i));
    return poolId;
  });
  return tx();
}

// Var olan bedenlerin stogu korunur; yeni eklenenler 0 ile baslar; cikarilanlar silinir.
function updatePool(poolId, name, sizeKeys) {
  const pool = getPool(poolId);
  if (!pool) return false;
  sizeKeys = cleanSizeKeys(sizeKeys);
  name = (name || '').trim() || pool.name;

  const tx = db.transaction(() => {
    db.prepare('UPDATE pools SET name = ? WHERE id = ?').run(name, poolId);

    const existing = {};
    pool.sizes.forEach((s) => (existing[s.size_key] = s.shared_stock));

    db.prepare('DELETE FROM pool_sizes WHERE pool_id = ?').run(poolId);
    const insertSize = db.prepare(
      'INSERT INTO pool_sizes (pool_id, size_key, sort_order, shared_stock) VALUES (?,?,?,?)'
    );
    sizeKeys.forEach((key, i) => insertSize.run(poolId, key, i, existing[key] || 0));
  });
  tx();
  return true;
}

// Tabloyu siler, bagli tasarimlarin tablo baglantisini kaldirir (tasarimlar silinmez).
function deletePool(poolId) {
  const tx = db.transaction(() => {
    db.prepare('UPDATE designs SET pool_id = NULL WHERE pool_id = ?').run(poolId);
    db.prepare('DELETE FROM pool_sizes WHERE pool_id = ?').run(poolId);
    db.prepare('DELETE FROM pools WHERE id = ?').run(poolId);
  });
  tx();
}

function getPoolSharedStock(poolId, sizeKey) {
  const row = db.prepare('SELECT shared_stock FROM pool_sizes WHERE pool_id = ? AND size_key = ?').get(poolId, sizeKey);
  return row ? row.shared_stock : 0;
}

function setPoolSharedStock(poolId, sizeKey, qty) {
  db.prepare('UPDATE pool_sizes SET shared_stock = ? WHERE pool_id = ? AND size_key = ?').run(
    Math.max(0, Number(qty) || 0),
    poolId,
    sizeKey
  );
}

// ---------------------------------------------------------------------
// TASARIM <-> TABLO
// ---------------------------------------------------------------------

function getDesigns(poolId = null) {
  if (poolId) {
    return db.prepare('SELECT * FROM designs WHERE active = 1 AND pool_id = ? ORDER BY name').all(poolId);
  }
  return db.prepare('SELECT * FROM designs WHERE active = 1 ORDER BY name').all();
}

function getDesign(designId) {
  return db.prepare('SELECT * FROM designs WHERE id = ?').get(designId);
}

function setDesignPool(designId, poolId) {
  db.prepare('UPDATE designs SET pool_id = ? WHERE id = ?').run(poolId || null, designId);
}

function setDesignStock(designId, qty) {
  db.prepare('UPDATE designs SET design_stock = ? WHERE id = ?').run(Math.max(0, Number(qty) || 0), designId);
}

function createDesign({ id, name, color, designStock, poolId }) {
  db.prepare(
    'INSERT INTO designs (id, name, color, design_stock, pool_id) VALUES (?,?,?,?,?)'
  ).run(id, name, color || '', Math.max(0, Number(designStock) || 0), poolId || null);
}

function setVariantMapping(designId, sizeKey, shopierVariantId) {
  db.prepare(
    `INSERT INTO design_variants (design_id, size_key, shopier_variant_id)
     VALUES (?,?,?)
     ON CONFLICT(design_id, size_key) DO UPDATE SET shopier_variant_id = excluded.shopier_variant_id`
  ).run(designId, sizeKey, shopierVariantId);
}

function getVariantMapping(designId, sizeKey) {
  return db
    .prepare('SELECT * FROM design_variants WHERE design_id = ? AND size_key = ?')
    .get(designId, sizeKey);
}

function findDesignByShopierProductId(shopierProductId) {
  return db.prepare('SELECT * FROM designs WHERE shopier_product_id = ?').get(shopierProductId);
}

// ---------------------------------------------------------------------
// HESAPLAMA
// ---------------------------------------------------------------------

// Tasarimin bagli oldugu tabloya gore hesaplanan gercek/gorunen stogu.
// Tabloya bagli degilse ya da bu beden o tabloda tanimli degilse null doner.
function effectiveStock(designId, sizeKey) {
  const design = getDesign(designId);
  if (!design || !design.pool_id) return null;
  const poolQty = db
    .prepare('SELECT shared_stock FROM pool_sizes WHERE pool_id = ? AND size_key = ?')
    .get(design.pool_id, sizeKey);
  if (!poolQty) return null; // bu beden bu tabloda tanimli degil
  return Math.max(0, Math.min(poolQty.shared_stock, design.design_stock));
}

// Bir tabloya bagli TUM tasarimlarin, gonderdigin resimdeki gibi tam tablosu.
// Her hucreye ayrica o (tasarim, beden) icin kayitli Shopier varyasyon id'sini
// de ekliyoruz ki admin panelinde eslestirme formu bunlari onceden dolu gorebilsin.
function getStockTable(poolId) {
  const pool = getPool(poolId);
  if (!pool) return { pool: null, table: [] };
  const designs = getDesigns(poolId);
  return {
    pool,
    table: designs.map((d) => ({
      design: d,
      cells: pool.sizes.map((s) => {
        const mapping = getVariantMapping(d.id, s.size_key);
        return {
          size: s.size_key,
          sharedStock: s.shared_stock,
          designStock: d.design_stock,
          effectiveStock: Math.max(0, Math.min(s.shared_stock, d.design_stock)),
          shopierVariantId: mapping ? mapping.shopier_variant_id : '',
        };
      }),
    })),
  };
}

// Bir satis geldiginde cagrilir. qty kadar dusurur (negatif de olabilir - iade/geri ekleme icin).
// Donen deger: Shopier'e push edilmesi gereken {designId, sizeKey, effectiveStock} listesi.
function applyDelta(designId, sizeKey, signedQty, meta = {}) {
  signedQty = Number(signedQty) || 0;
  if (signedQty === 0) return { skipped: true, reason: 'sifir adet' };

  const design = getDesign(designId);
  if (!design) throw new Error(`Bilinmeyen tasarim: ${designId}`);
  if (!design.pool_id) return { skipped: true, reason: 'bu tasarim hicbir tabloya bagli degil' };

  const sizeRow = db
    .prepare('SELECT * FROM pool_sizes WHERE pool_id = ? AND size_key = ?')
    .get(design.pool_id, sizeKey);
  if (!sizeRow) return { skipped: true, reason: `'${sizeKey}' bedeni bu tasarimin tablosunda tanimli degil` };

  const tx = db.transaction(() => {
    const newShared = Math.max(0, sizeRow.shared_stock + signedQty);
    db.prepare('UPDATE pool_sizes SET shared_stock = ? WHERE pool_id = ? AND size_key = ?').run(
      newShared,
      design.pool_id,
      sizeKey
    );

    const newDesignStock = Math.max(0, design.design_stock + signedQty);
    db.prepare('UPDATE designs SET design_stock = ? WHERE id = ?').run(newDesignStock, designId);

    db.prepare(
      `INSERT INTO sale_events (design_id, size_key, qty, order_ref, raw_payload, applied, note)
       VALUES (?,?,?,?,?,1,?)`
    ).run(
      designId,
      sizeKey,
      signedQty,
      meta.orderRef || null,
      meta.rawPayload ? JSON.stringify(meta.rawPayload) : null,
      `tablo havuzu ${sizeRow.shared_stock}->${newShared}, tasarim stogu ${design.design_stock}->${newDesignStock}`
    );

    return { newShared, newDesignStock };
  });
  const { newShared, newDesignStock } = tx();

  // Etkilenen tum hucreleri toparla: bu tabloda bu bedene sahip TUM tasarimlar + bu tasarimin TUM bedenleri
  const affected = new Map();
  for (const d of getDesigns(design.pool_id)) {
    const eff = d.id === designId
      ? Math.max(0, Math.min(newShared, newDesignStock))
      : Math.max(0, Math.min(newShared, d.design_stock));
    affected.set(`${d.id}|${sizeKey}`, { designId: d.id, sizeKey, effectiveStock: eff });
  }
  for (const s of getPoolSizes(design.pool_id)) {
    const shared = s.size_key === sizeKey ? newShared : s.shared_stock;
    const eff = Math.max(0, Math.min(shared, newDesignStock));
    affected.set(`${designId}|${s.size_key}`, { designId, sizeKey: s.size_key, effectiveStock: eff });
  }

  return {
    skipped: false,
    poolId: design.pool_id,
    sharedStockAfter: newShared,
    designStockAfter: newDesignStock,
    cellsToPush: Array.from(affected.values()),
  };
}

module.exports = {
  getPools,
  getPool,
  parseSizeList,
  createPool,
  updatePool,
  deletePool,
  getPoolSharedStock,
  setPoolSharedStock,
  getDesigns,
  getDesign,
  setDesignPool,
  setDesignStock,
  createDesign,
  setVariantMapping,
  getVariantMapping,
  findDesignByShopierProductId,
  effectiveStock,
  getStockTable,
  applyDelta,
};
