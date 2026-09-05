// SQLite tabanli, dosyaya yazan basit veritabani. Ek bir sunucu/servis kurmaya gerek yok.
//
// v2: WooCommerce eklentisiyle AYNI mimari - artik tek bir ortak havuz yok, istedigin
// kadar bagimsiz "tablo" (pool) tanimlanabiliyor (or. "Cocuk Tisort Bedenleri",
// "Sweatshirt Bedenleri"). Her tasarim EN FAZLA bir tabloya baglanir.
const path = require('path');
const fs = require('fs');
const Database = require('better-sqlite3');

const DATA_DIR = path.join(__dirname, '..', 'data');
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });

const db = new Database(path.join(DATA_DIR, 'stok.db'));
db.pragma('journal_mode = WAL');

db.exec(`
CREATE TABLE IF NOT EXISTS pools (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Bir tabloya ait her bedenin ORTAK stogu (o tabloya bagli TUM tasarimlar bunu paylasir)
CREATE TABLE IF NOT EXISTS pool_sizes (
  pool_id INTEGER NOT NULL REFERENCES pools(id),
  size_key TEXT NOT NULL,          -- '3-4', 'S', ...
  sort_order INTEGER NOT NULL,
  shared_stock INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (pool_id, size_key)
);

CREATE TABLE IF NOT EXISTS designs (
  id TEXT PRIMARY KEY,                 -- kisa slug, orn 'bismillah'
  name TEXT NOT NULL,                  -- 'Bismillah'
  color TEXT,                          -- 'Ekru' / 'Siyah'
  design_stock INTEGER NOT NULL DEFAULT 0,  -- 'Tasarim Stogu' - bu tasarima ozel, bedenden bagimsiz sinir
  shopier_product_id TEXT,             -- Shopier'daki urun id'si (eslesme yapilinca doldurulur)
  pool_id INTEGER REFERENCES pools(id),-- hangi tabloya bagli (NULL = hicbir tabloya bagli degil, bu sistemin disinda)
  active INTEGER NOT NULL DEFAULT 1
);

-- Her (tasarim, beden) kombinasyonunun Shopier'daki varyasyon id'si.
CREATE TABLE IF NOT EXISTS design_variants (
  design_id TEXT NOT NULL REFERENCES designs(id),
  size_key TEXT NOT NULL,
  shopier_variant_id TEXT,
  PRIMARY KEY (design_id, size_key)
);

-- Gelen her siparis/webhook olayinin ham kaydi - sorun cikarsa geriye donup bakmak icin.
CREATE TABLE IF NOT EXISTS sale_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  received_at TEXT NOT NULL DEFAULT (datetime('now')),
  design_id TEXT,
  size_key TEXT,
  qty INTEGER,
  order_ref TEXT,
  raw_payload TEXT,
  applied INTEGER NOT NULL DEFAULT 0,
  note TEXT
);
`);

function seedIfEmpty() {
  const poolCount = db.prepare('SELECT COUNT(*) c FROM pools').get().c;
  if (poolCount === 0) {
    const insertPool = db.prepare('INSERT INTO pools (id, name) VALUES (1, ?)');
    insertPool.run('Çocuk Tişört Bedenleri');

    const insertSize = db.prepare(
      'INSERT INTO pool_sizes (pool_id, size_key, sort_order, shared_stock) VALUES (1,?,?,?)'
    );
    const seedSizes = [
      ['3-4', 1, 5],
      ['5-6', 2, 8],
      ['7-8', 3, 6],
      ['9-10', 4, 15],
      ['11-12', 5, 9],
    ];
    const tx1 = db.transaction((rows) => rows.forEach((r) => insertSize.run(...r)));
    tx1(seedSizes);

    const insertDesign = db.prepare(
      'INSERT INTO designs (id, name, color, design_stock, pool_id) VALUES (?,?,?,?,1)'
    );
    // Ahmet'in gonderdigi tablodaki tasarimlar ve "Tasarim Stogu" degerleri
    const seedDesigns = [
      ['dosdogru-ol', 'Dosdoğru Ol', 'Siyah', 15],
      ['o-bana-yeter', 'O Bana Yeter', 'Ekru', 13],
      ['tesettur', 'Tesettür', 'Ekru', 12],
      ['bismillah', 'Bismillah', 'Ekru', 5],
      ['hz-hamza', 'Hz Hamza', 'Ekru', 4],
      ['halid-bin-velid', 'Halid Bin Velid', 'Ekru', 7],
      ['selahaddin-eyyubi', 'Selahaddin Eyyubi', 'Ekru', 8],
      ['guzel-goren-guzel-dusunur', 'Güzel Gören Güzel Düşünür', 'Ekru', 9],
      ['fatih-sultan-mehmet', 'Fatih Sultan Mehmet (FSM)', 'Ekru', 12],
    ];
    const tx2 = db.transaction((rows) => rows.forEach((r) => insertDesign.run(...r)));
    tx2(seedDesigns);
  }
}

module.exports = { db, seedIfEmpty };
