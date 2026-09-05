require('dotenv').config();
const express = require('express');
const path = require('path');
const { seedIfEmpty } = require('./db');

seedIfEmpty();

const app = express();

// --- cok basit HTTP Basic Auth ile admin panelini koru ---
function basicAuth(req, res, next) {
  const user = process.env.ADMIN_USER;
  const pass = process.env.ADMIN_PASSWORD;
  if (!user || !pass) return next(); // ayarlanmadiysa koruma yok (yerel test icin)

  const header = req.headers.authorization || '';
  const [, encoded] = header.split(' ');
  const decoded = encoded ? Buffer.from(encoded, 'base64').toString() : '';
  const [u, p] = decoded.split(':');

  if (u === user && p === pass) return next();
  res.set('WWW-Authenticate', 'Basic realm="admin"');
  return res.status(401).send('Yetkisiz');
}

app.use('/admin', basicAuth, express.static(path.join(__dirname, '..', 'public'), { index: 'admin.html' }));
app.use('/admin/api', basicAuth, require('./routes/admin'));

// Shopier'in webhook/OSB istegi bu adrese gelecek (basic auth YOK - disaridan erisilebilir olmali)
app.use('/webhook', require('./routes/webhook'));

app.get('/', (req, res) => res.redirect('/admin'));
app.get('/health', (req, res) => res.json({ ok: true }));

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Shopier stok senkronizasyon servisi calisiyor: http://localhost:${PORT}`);
  console.log(`Admin panel: http://localhost:${PORT}/admin`);
  console.log(`Webhook adresi (Shopier'e tanimlanacak): http://<sunucu-adresin>/webhook/shopier-order`);
});
