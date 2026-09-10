# Miras Ortak Stok Havuzu — v2 (WooCommerce + Shopier)

Aynı bedeni paylaşan birden fazla tasarımın stoğunu tek yerden yöneten WordPress/WooCommerce eklentisi. **v2 ile birlikte artık Shopier'i de aynı sistemin içinde barındırıyor** — ayrı bir Node.js servisine (eski `shopier-stok-sync` klasörü) ihtiyaç kalmadı, her şey bu eklentinin içinde, sitenin kendi sunucusunda çalışıyor.

## Nasıl çalışıyor

Tek gerçek kaynak (kaynak veri) artık **bu WordPress sitesi**: havuzlar, bedenler, tasarım stokları, WooCommerce varyasyon eşlemeleri ve Shopier ürün/varyasyon eşlemeleri hep burada, site veritabanında tutuluyor. Bir satış (WooCommerce'de ya da Shopier'de) olduğunda:

1. Etkilenen (tasarım, beden) hücreleri hesaplanır (`min(havuzun ortak beden stoğu, tasarımın kendi stoğu)`).
2. İlgili WooCommerce varyasyonuna **hemen** yazılır (müşteri anında doğru stoğu görür).
3. Eşleştirdiğin Shopier varyasyonlarına da **arka planda** (WP-Cron ile, checkout'u/kaydetmeyi bekletmeden) aynı stok gönderilir.

Yani hangi kanaldan satış gelirse gelsin (WooCommerce ya da Shopier), her iki mağazada da aynı anda doğru stok görünür.

## Kurulum

1. `miras-ortak-stok` klasörünü sitenin `wp-content/plugins/` klasörüne yükle, WordPress admin > Eklentiler'den etkinleştir. (WooCommerce aktif olmalı, yoksa eklenti kendini otomatik kapatır.)
2. Sol menüde **"Ortak Stok Havuzu"** açılır — buradan tablo/havuz oluştur (ör. "Çocuk Tişört Bedenleri" — 3-4 Yaş, 5-6 Yaş, ...), tasarımları (varyasyonlu ürünleri) ekle, WooCommerce varyasyon eşlemesini **Stok Tablosu** ekranından yap.
3. **Shopier Bağlantısı** alt sayfasına git:
   - Shopier panelinden **Hesap Yönetimi > Kişisel Erişim Anahtarı**'nı gir, Kaydet.
   - **"🔗 Webhook Aboneliği Oluştur"** butonuna bas — Shopier'e otomatik olarak `order.created` aboneliği açılır, dönen doğrulama token'ı otomatik kaydedilir. (Bu adım Erişim Anahtarı kaydedildikten sonra yapılmalı.)
   - İstersen DRY RUN'ı açık bırak — açıkken Shopier'e **hiçbir yazma isteği gitmez**, sadece ne gönderileceği loglanır. Her şeyi test edip gözden geçirdikten sonra kapat.
4. **Stok Tablosu** ekranına dön, her tasarımın **"Shopier Ürün ID"**sini gir, üstteki **"🔄 Shopier Bedenlerini Getir"** butonuna bas (hesabındaki tüm beden seçeneklerini bir kerede çeker), her hücrenin altındaki Shopier açılır listesinden doğru bedeni seç.
5. DRY RUN'ı kapatıp bir test satışıyla (ör. Stok Tablosu'nda bir stok değerini elle değiştirip) gerçekten Shopier'e yazdığını doğrula.

## Bilinen kısıt: Shopier `/products` 403

Bu hesabın Erişim Anahtarı ile `GET`/`PUT /products/{id}` (stok okuma/yazma) hâlâ **403 Forbidden** dönebilir — bu, Shopier destek tarafında çözülmesi gereken hesaba özel bir erişim kısıtlaması (`hello@shopier.com`). Erişim açılana kadar DRY RUN'ı kapatmadan sistemi kurup test edebilirsin; erişim açılınca kod değişikliğine gerek kalmadan (aynı kod, sadece artık 403 yerine 200 dönecek) çalışmaya başlar. Beden eşleme ekranı (`/selections`, `/variations`) bu kısıttan etkilenmiyor, zaten çalışıyor.

## WP-Cron güvenilirliği

Shopier'e gönderim arka planda WordPress'in kendi "WP-Cron" mekanizmasıyla çalışır — normalde siteye herhangi bir ziyaret bunu tetikler, ama düşük trafikli saatlerde gecikebilir. Daha güvenilir/gerçek-zamanlıya yakın olması için Natro'daki hosting panelinden (ya da barındırıcının cPanel'inden) **gerçek bir sunucu cron'u** kurup her dakika `wp-cron.php`'yi tetiklemeni öneririm — bu standart bir WordPress tavsiyesidir, istersen bu adımı birlikte de yapabiliriz.

## Eski Node.js servisi (`shopier-stok-sync`)

Bu eklenti, o servisin yaptığı her şeyi (Shopier webhook'unu karşılama, Shopier'e stok yazma) artık kendi içinde yapıyor. `shopier-stok-sync` klasörünü/servisini artık **çalıştırmana gerek yok** — istersen kapatabilirsin, ayrı bir sunucu (Render vb.) tutmana gerek kalmadı.

## Proje yapısı

```
miras-ortak-stok.php                        Bootstrap, sürüm/upgrade, require'lar
includes/
  class-mos-db.php                          Veritabanı katmanı: havuzlar, eşlemeler, Shopier ayarları/idempotency
  class-mos-engine.php                      Stok hesaplama + WooCommerce'e yazma + Shopier push tamponu/cron planlama
  class-mos-hooks.php                       WooCommerce sipariş/stok olaylarını yakalar
  class-mos-admin.php                       Yönetim paneli: havuzlar, stok tablosu, Shopier ayarları, tüm AJAX uçları
  class-mos-shopier-client.php              Shopier REST API ile konuşan tek yer (eski shopierClient.js'in PHP karşılığı)
  class-mos-shopier-webhook.php             Shopier sipariş webhook'u (wp-json/mos/v1/shopier-order)
  views/                                    Yönetim paneli ekranları
assets/                                     admin.css / admin.js
```
