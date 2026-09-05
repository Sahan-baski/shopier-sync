const express = require('express');
const engine = require('../stockEngine');
const { pushVariantStock, DRY_RUN } = require('../shopierClient');

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
      const mapping = engine.getVariantMapping(cell.designId, cell.sizeKey);
      await pushVariantStock({
        shopierVariantId: mapping && mapping.shopier_variant_id,
        newStock: cell.effectiveStock,
      }).catch(() => {});
    }
    res.json({ ok: true, outcome });
  } catch (e) {
    res.status(400).json({ ok: false, error: e.message });
  }
});

// Bir tablonun bir bedeninin ortak stogunu elle duzelt
router.post('/pools/:poolId/sizes/:sizeKey', (req, res) => {
  engine.setPoolSharedStock(Number(req.params.poolId), req.params.sizeKey, req.body.sharedStock);
  res.json({ ok: true });
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

// Tasarimin stogunu / hangi tabloya bagli oldugunu / shopier urun id'sini guncelle
router.post('/designs/:designId', (req, res) => {
  if (req.body.designStock !== undefined) {
    engine.setDesignStock(req.params.designId, req.body.designStock);
  }
  if (req.body.poolId !== undefined) {
    engine.setDesignPool(req.params.designId, req.body.poolId ? Number(req.body.poolId) : null);
  }
  if (req.body.shopierProductId !== undefined) {
    require('../db')
      .db.prepare('UPDATE designs SET shopier_product_id = ? WHERE id = ?')
      .run(req.body.shopierProductId, req.params.designId);
  }
  res.json({ ok: true });
});

// Tasarim + beden -> Shopier varyasyon id eslemesi
router.post('/designs/:designId/variants/:sizeKey', (req, res) => {
  engine.setVariantMapping(req.params.designId, req.params.sizeKey, req.body.shopierVariantId);
  res.json({ ok: true });
});

module.exports = router;
