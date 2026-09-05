# Shopier Stok Senkronizasyon Servisi (Şahan Baskı / Miras Çocuk) — v2 Çoklu Tablo

> WooCommerce sitesindeki ödeme ekranı sorunu çözülene kadar bu servis, WooCommerce eklentisiyle **aynı iş mantığını** Shopier üzerinde çalıştırıp asıl kullandığın stok sistemi oluyor. Aşağıdaki "Kurulum adımları" bölümü, bunu canlıya almak için gereken adımları listeliyor.

Bu servis şu kuralı otomatikleştirmek için yazıldı: **bir tabloya bağlı bir tasarımda bir beden satıldığında, aynı tablodaki tüm diğer tasarımların stoğu da birlikte düşer** — çünkü gerçekte stok tutulan şey "tasarım" değil, o bedendeki boş ürün ve tasarımlar o ortak havuzdan besleniyor.

WooCommerce eklentisiyle **aynı mimariyi** kullanıyor: istediğin kadar bağımsız **tablo** tanımlayabilirsin (ör. "Çocuk Tişört Bedenleri", "Sweatshirt Bedenleri"). Tablolar birbirinden tamamen bağımsız — bir tablodaki satış diğerini etkilemez.

Mevcut iş yönetimi uygulamandan (Node.js/Express + MongoDB, Render'da çalışan) **bağımsız, ayrı ve küçük** bir servis olarak kuruldu — ona dokunmuyor.

## Nasıl çalışıyor (iş mantığı)

Her tablonun iki ayrı sayacı var:

1. **Ortak beden stoğu**: tablodaki her beden için tek bir sayı. O tabloya bağlı herhangi bir tasarımda o bedenden satış olunca düşer, **o tabloya bağlı tüm tasarımları** etkiler.
2. **Tasarım Stoğu**: her tasarımın kendine ait, bedenden bağımsız bir sınır (ör. elde hazır baskı/transfer sayısı). Bir tasarım satıldığında sadece kendi bu sayısı düşer.

Shopier'e (ve WooCommerce eklentisinde de aynı şekilde) **push edilen gerçek stok değeri** ikisinin küçüğü: `min(tablonun o bedendeki ortak stoğu, tasarımın kendi stoğu)`. Bu davranışı WooCommerce tarafında birlikte örneklerle doğruladık — otomatik testlerle de kapsanıyor.

## Kurulum (yerelde/test için)

```bash
npm install
cp .env.example .env
npm start
```

Tarayıcıda `http://localhost:3000/admin` — kullanıcı adı/şifre `.env` dosyasındaki `ADMIN_USER` / `ADMIN_PASSWORD`. Panelde:

- **"+ Yeni Tablo Ekle"** ile bağımsız bir stok tablosu oluşturabilirsin (ör. "Sweatshirt Bedenleri" — S, M, L, XL).
- Sağ üstteki dropdown'dan tablolar arasında geçiş yapabilir, **"✏️ Düzenle"** ile bir tablonun adını/beden listesini değiştirebilir, **"🗑️ Sil"** ile kaldırabilirsin.
- **"Yeni tasarım ekle"** ile seçili tabloya yeni bir ürün/tasarım ekleyebilirsin (id, ad, renk, başlangıç Tasarım Stoğu).
- Her tasarım satırının sağındaki **"🔗 Bağla"** butonuyla o tasarımı Shopier'deki gerçek ürününe bağlarsın: Shopier Ürün ID'sini gir, **"🔄 Shopier'den Çek"** butonuna bas — Shopier'deki GERÇEK varyasyon ID'lerini (İncele/Inspect Element'e hiç gerek kalmadan) otomatik getirir ve mümkünse bedenleri otomatik eşleştirir; sen sadece açılan listeden doğru bedeni seçip kontrol edersin. Bağlantı tamamlanınca buton yeşil **"🔗 Bağlı"** olur.
- **"Test satışı"** ile Shopier'e hiç bağlanmadan bir satış simüle edip aynı tablodaki tüm tasarımların o bedeninin birlikte düştüğünü görebilirsin.
- Üstteki "Toplam (ortak havuz)" satırı ve her tasarımın "Tasarım Stoğu" hücresi elle güncellenebilir (✏️ gerçek stok); diğer tüm hücreler otomatik hesaplanır ve salt okunur (🔒 sitede görünen).
- **Gerçek stoğu buraya girersin, Shopier'i bu servis günceller**: yeni baskı aldığında/tişört stoğu değiştiğinde ilgili hücreyi değiştirip Enter'a basman/başka yere tıklaman yeterli — hesaplanan yeni "sitede görünen" değer, eşleştirdiğin tüm Shopier varyasyonlarına o an otomatik gönderilir (satış beklemeye gerek yok). Panelde ayrıca **"🔄 Tümünü Shopier'e Gönder"** butonu var — mesela yeni bir tasarımı eşleştirdikten hemen sonra, o tablodaki her şeyi tek seferde Shopier'e (yeniden) göndermek için kullanılır.
- **Ne almam lazım, ne bitmiş görürsün**: bir hücre 0'a inince kırmızı, 3 ve altına inince turuncu renkte vurgulanıyor — hem ortak beden stoğunda hem Tasarım Stoğu'nda. Panele her baktığında hangi bedeni/tasarımı yenilemen gerektiğini bir bakışta görürsün, ayrıca bir yere not almana gerek yok.

Servis kurulunca otomatik olarak senin ilk gönderdiğin tablo (9 tasarım, 5 beden) "Çocuk Tişört Bedenleri" adıyla seed edilmiş halde geliyor — sıfırdan girmene gerek yok.

`.env` içindeki `DRY_RUN=true` kaldığı sürece **Shopier'e hiçbir istek atılmaz** — sadece ne gönderileceği konsola loglanır. Sistemi Shopier'e bağlamadan önce güvenle deneyebilirsin.

## Shopier tarafıyla ilgili artık NETLEŞEN her şey

Shopier'in resmi developer.shopier.com dokümantasyonundan (tam OpenAPI şeması) doğrulandı:

1. **Webhook aboneliği bir API çağrısıyla oluşturuluyor** (`POST /webhooks`, gövde `{event, url}`), OSB'deki gibi tek bir "Bildirim URL" kutusuna yapıştırıp kaydetmek yeterli değil. Abonelik oluşturulunca dönen `token` alanı imza doğrulaması için `SHOPIER_ORDER_WEBHOOK_SECRET`'a girilir (bu token SADECE oluşturma anındaki cevapta bir kez veriliyor, kaybedilirse abonelik silinip yeniden oluşturulmalı).
2. **Sipariş webhook'unun tam JSON şeması** gerçek bir siparişle doğrulandı: `body.id` (sipariş no), `body.lineItems[].productId` (ürün id), `body.lineItems[].selection[0].id` (varyasyon/beden id'si — "selection id"), `body.lineItems[].quantity` (adet).
3. **Stok güncelleme**: Shopier'de ayrı bir "stok güncelle" endpoint'i yok. `GET /products/{id}` o ürünün TÜM varyasyonlarını (`selectionId`, `selectionTitle` — ör. "3-4 Yaş" — ve `stockQuantity` ile) döndürüyor; `PUT /products/{id}` ile de güncelleniyor (tüm varyasyon listesini geri gönderiyoruz, sadece hedef olanın `stockQuantity`'sini değiştirerek — diğerlerini kaybetmemek için). Bu servis bunu otomatik yapıyor.

**Kritik bir bulgu:** Shopier panelinde ürün üzerinde sağ tık → "İncele"/Inspect Element ile bulunan varyasyon ID'si, API'nin kullandığı gerçek `selectionId` ile **AYNI DEĞİL**. Bu yüzden mapping ekranında artık ID'yi elle kopyalamak yerine **"🔄 Shopier'den Çek"** butonu var — bu, `GET /products/{id}` ile Shopier'den doğru ID+başlık eşlemesini doğrudan çekip listeler; sen sadece doğru bedeni seçersin. Daha önce İncele ile girilmiş olan eşleştirmeler (varsa) bu yöntemle tekrar kontrol edilip düzeltilmeli.

**Öğrendiğimiz önemli bir kural:** Shopier, webhook'a **5 saniye içinde 200 OK** dönmezsen bildirimi başarısız sayıp 1dk/10dk/1sa/2sa/4sa/8sa/24sa/48sa/72sa arayla toplam 9 kez tekrar deniyor. Bunu iki şekilde hesaba kattık: (1) sunucu, gelen siparişi önce kendi veritabanında (çok hızlı) işleyip Shopier'e hemen 200 OK dönüyor, Shopier'e geri yazma işlemini cevaptan SONRA arka planda yapıyor — böylece yavaş bir dış istek yüzünden "başarısız" sayılmıyor; (2) bu tekrar deneme mekanizması, sunucunun bir an için yavaş/uykuda olması ihtimaline karşı da ekstra bir güvenlik ağı sağlıyor.

## Kurulum adımları (senin yapman gerekenler)

1. `.env` dosyasına `SHOPIER_ACCESS_KEY`'i gir (Shopier panelinden Kişisel Erişim Anahtarı).
2. Bu servisin admin panelinde her tasarımın satırındaki **"🔗 Bağla"** butonuna tıkla, Shopier Ürün ID'sini gir, **"🔄 Shopier'den Çek"**'e bas, açılan listelerden her bedenin doğru karşılığını seç, Kaydet'e bas. (İncele/Inspect Element'e hiç gerek yok, elle ID kopyalama hatası riski ortadan kalktı.)
3. Webhook aboneliğini `https://<sunucu-adresin>/webhook/shopier-order` adresine kurup dönen `token`'ı `SHOPIER_ORDER_WEBHOOK_SECRET`'a gir.
4. Tüm tasarımlar eşleştikten sonra `DRY_RUN=false` yapılır, canlıya alınır — panelden bir test girişiyle Shopier'e gerçekten yazdığını doğrula.

## Render.com'a dağıtım

1. Bu klasörü kendi GitHub reponda bir repo yap, push et.
2. Render'da "New Web Service" → repoyu seç.
3. Build command: `npm install`  |  Start command: `npm start`
4. Environment Variables kısmına `.env.example`'daki değişkenleri gerçek değerleriyle gir.
5. Render sana `https://....onrender.com` gibi bir adres verecek — webhook adresin `https://....onrender.com/webhook/shopier-order` olacak.
6. **Önemli:** `data/` klasörü SQLite dosyasını tutuyor. Render'ın ücretsiz planında disk kalıcı olmayabilir — "Persistent Disk" eklemen gerekebilir. Canlıya geçerken hatırlat, birlikte hallederiz.

## WooCommerce ile ilişkisi

WooCommerce eklentisi ile bu servis **şu an birbirinden bağımsız** çalışıyor — aynı iş mantığını (tablo/pool modeli) paylaşıyorlar ama stokları ayrı tutuyorlar. İleride "aynı tasarımı iki kanalda da satıyorum, ikisi aynı ortak havuzdan düşsün" istersen, tek gerçek kaynak + iki satış kanalı şeklinde birleştirebiliriz.

## Proje yapısı

```
src/
  db.js                SQLite tablo tanımları: pools, pool_sizes, designs, design_variants, sale_events
  stockEngine.js        Asıl iş mantığı: çoklu tablo yönetimi, satış geldiğinde hangi hücreler düşer (test edildi)
  shopierClient.js       Shopier ile konuşan tek yer (2 TODO burada)
  routes/webhook.js      Shopier'den gelen siparişi karşılayan uç nokta
  routes/admin.js         Panel için API: tablo CRUD, tasarım ekleme, test satışı
public/admin.html        Çoklu tablo paneli: tablo ekle/düzenle/sil, dropdown, stok tablosu
data/                    SQLite veritabanı burada oluşur (ilk çalıştırmada otomatik)
```
