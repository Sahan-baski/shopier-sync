const express = require('express');
const engine = require('../stockEngine');
const { pushVariantStock, fetchProduct, fetchChildSizeSelections, DRY_RUN } = require('../shopierClient');

const router = express.Router();
router.use(express.json());

router.get('/mode', (req, res) => res.json({ dryRun: DRY_RUN }));

// Tum tablolari (havuzlari) listele
router.get('/pools', (req, res) => {
  res.json({ pools: engine.getPools() });
});

router.post('/pools', (req, res) => {
  const sizes = engine.parseSizeList(req.body.sizesCsv);
  if (!sizes.length) return res.status(400).json({ ok: false, error: 'en az bir beden gerekli' });
  const poolId = engine.createPool(req.body.name, sizes);
  res.json({ ok: true, poolId });
});

router.put('/pools/:poolId', (req, res) => {
  const sizes = engine.parseSizeList(req.body.sizesCsv);
  if (!sizes.length) return res.status(400).json({ ok: false, error: 'en az bir beden gerekli' });
  const ok = engine.updatePool(Number(req.params.poolId), req.body.name, sizes);
  res.json({ ok });
});

router.delete('/pools/:poolId', (req, res) => {
  engine.deletePool(Number(req.params.poolId));
  res.json({ ok: true });
});

// Belirli bir tablonun stok tablosu (goderdigin resimdeki gorunum)
router.get('/pools/:poolId/stock-table', (req, res) => {
  res.json(engine.getStockTable(Number(req.params.poolId)));
});

// Elle test satisi uygula (Shopier baglanmadan is mantigini denemek icin)
router.post('/test-sale', async (req, res) => {
  const { designId, sizeKey, qty } = req.body || {};
  try {
    const outcome = engine.applyDelta(designId, sizeKey, -1 * Math.abs(Number(qty) || 1), { orderRef: 'MANUEL-TEST' });
    if (outcome.skipped) return res.status(400).json({ ok: false, error: outcome.reason });

    for (const cell of outcome.cellsToPush) {
      const target = engine.getShopierTarget(cell.designId, cell.sizeKey);
      await pushVariantStock({ ...target, newStock: cell.effectiveStock }).catch(() => {});
    }
    res.json({ ok: true, outcome });
  } catch (e) {
    res.status(400).json({ ok: false, error: e.message });
  }
});

// Bir tablonun bir bedeninin ortak stogunu elle duzelt.
// Bu, o bedeni paylasan TUM tasarimlari etkiler - hepsini Shopier'e de gonderiyoruz.
router.post('/pools/:poolId/sizes/:sizeKey', async (req, res) => {
  const poolId = Number(req.params.poolId);
  const sizeKey = req.params.sizeKey;
  engine.setPoolSharedStock(poolId, sizeKey, req.body.sharedStock);

  const cells = engine.cellsForPoolSize(poolId, sizeKey);
  const pushResults = [];
  for (const cell of cells) {
    const target = engine.getShopierTarget(cell.designId, cell.sizeKey);
    const r = await pushVariantStock({ ...target, newStock: cell.effectiveStock }).catch((e) => ({
      ok: false,
      error: e.message,
    }));
    pushResults.push({ cell, ...r });
  }
  res.json({ ok: true, pushResults });
});

// Secili tablodaki TUM hucreleri Shopier'e (yeniden) gonder.
// Ilk kurulumdan sonra ya da "emin olmak icin hepsini yenile" istediginde kullanilir.
router.post('/pools/:poolId/sync-to-shopier', async (req, res) => {
  const poolId = Number(req.params.poolId);
  const cells = engine.cellsForPool(poolId);
  let pushed = 0;
  let skipped = 0;
  const details = [];
  for (const cell of cells) {
    const target = engine.getShopierTarget(cell.designId, cell.sizeKey);
    const r = await pushVariantStock({ ...target, newStock: cell.effectiveStock }).catch((e) => ({
      ok: false,
      error: e.message,
    }));
    if (r && r.ok) pushed++; else skipped++;
    details.push({ cell, ...r });
  }
  res.json({ ok: true, pushed, skipped, total: cells.length, details });
});

// Yeni tasarim ekle
router.post('/designs', (req, res) => {
  const { id, name, color, designStock, poolId } = req.body || {};
  if (!id || !name) return res.status(400).json({ ok: false, error: 'id ve name gerekli' });
  try {
    engine.createDesign({ id, name, color, designStock, poolId: poolId ? Number(poolId) : null });
    res.json({ ok: true });
  } catch (e) {
    res.status(400).json({ ok: false, error: e.message });
  }
});

// Tasarimin stogunu / hangi tabloya bagli oldugunu / shopier urun id'sini guncelle.
// designStock degisiyorsa, bu tasarimin TUM bedenlerini Shopier'e de gonderiyoruz.
router.post('/designs/:designId', async (req, res) => {
  let pushResults = [];
  if (req.body.designStock !== undefined) {
    engine.setDesignStock(req.params.designId, req.body.designStock);
    const cells = engine.cellsForDesign(req.params.designId);
    for (const cell of cells) {
      const target = engine.getShopierTarget(cell.designId, cell.sizeKey);
      const r = await pushVariantStock({ ...target, newStock: cell.effectiveStock }).catch((e) => ({
        ok: false,
        error: e.message,
      }));
      pushResults.push({ cell, ...r });
    }
  }
  if (req.body.poolId !== undefined) {
    engine.setDesignPool(req.params.designId, req.body.poolId ? Number(req.body.poolId) : null);
  }
  if (req.body.shopierProductId !== undefined) {
    require('../db')
      .db.prepare('UPDATE designs SET shopier_product_id = ? WHERE id = ?')
      .run(req.body.shopierProductId, req.params.designId);
  }
  res.json({ ok: true, pushResults });
});

// Tasarim + beden -> Shopier varyasyon id eslemesi
router.post('/designs/:designId/variants/:sizeKey', (req, res) => {
  engine.setVariantMapping(req.params.designId, req.params.sizeKey, req.body.shopierVariantId);
  res.json({ ok: true });
});

// Verilen Shopier Urun ID'sinin GERCEK varyasyonlarini (selectionId + selectionTitle,
// ornegin "097613d2b7a41249" + "3-4 Yaş") Shopier'den canli ceker. Boylece admin panelde
// "İncele" ile elle ID kopyalamaya hic gerek kalmiyor - kullanici sadece dogru basligi
// (bedeni) secer, ID'yi biz dogrudan Shopier'in kendisinden aliyoruz.
router.get('/shopier/products/:productId/variants', async (req, res) => {
  try {
    const { variants } = await fetchProduct(req.params.productId);
    res.json({ ok: true, variants });
  } catch (e) {
    res.status(400).json({ ok: false, error: e.message });
  }
});

// YENI: Bu tablodaki (havuzdaki) TUM tasarimlarin Shopier'de SU AN CANLI gorunen
// stok sayilarini ceker - hicbir sey DEGISTIRMEZ, sadece okur (GET /products/{id}).
// /products erisimi 403 verdigi surece bu da calismaz, ama erisim acilinca ekstra
// kod degisikligine gerek kalmadan calismaya baslar. Admin panelinde "☁️ Shopier'deki
// Güncel Stoğu Çek" butonu ve sayfa acilirken/periyodik olarak bunu cagirir.
router.get('/pools/:poolId/live-shopier-stock', async (req, res) => {
  const poolId = Number(req.params.poolId);
  const { table } = engine.getStockTable(poolId);
  const designs = {};
  const errors = {};
  for (const row of table) {
    const design = row.design;
    if (!design.shopier_product_id) continue;
    try {
      const { variants } = await fetchProduct(design.shopier_product_id);
      const bySelection = new Map(variants.map((v) => [String(v.selectionId), v.stockQuantity]));
      const sizes = {};
      for (const cell of row.cells) {
        if (cell.shopierVariantId && bySelection.has(String(cell.shopierVariantId))) {
          sizes[cell.size] = bySelection.get(String(cell.shopierVariantId));
        }
      }
      designs[design.id] = sizes;
    } catch (e) {
      errors[design.id] = e.message;
    }
  }
  res.json({ ok: true, designs, errors });
});

// YENI (06.09.2026): /products/{id} bu hesapta 403 verdigi icin urune ozel cekim
// calismiyor. Bunun yerine hesap genelindeki TUM "beden" secimlerini (ör. "3-4 Yaş")
// /selections + /variations uzerinden ceker - urun ID'sine ihtiyac YOK, cunku bu
// ID'ler gercek siparis gecmisinde TUM tasarimlar arasinda ayni cikti (ortak/global).
router.get('/shopier/child-sizes', async (req, res) => {
  try {
    const sizes = await fetchChildSizeSelections();
    res.json({ ok: true, sizes });
  } catch (e) {
    res.status(400).json({ ok: false, error: e.message });
  }
});

module.exports = router;
