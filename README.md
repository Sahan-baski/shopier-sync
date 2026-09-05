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
- Her tasarım satırının sağındaki **"🔗 Bağla"** butonuyla o tasarımı Shopier'deki gerçek ürününe bağlarsın: Shopier Ürün ID'si + her beden için Shopier Varyasyon ID'si. Bağlantı tamamlanınca buton yeşil **"🔗 Bağlı"** olur. Bu artık panelden yapılıyor — API/komut satırı gerekmiyor.
- **"Test satışı"** ile Shopier'e hiç bağlanmadan bir satış simüle edip aynı tablodaki tüm tasarımların o bedeninin birlikte düştüğünü görebilirsin.
- Üstteki "Toplam (ortak havuz)" satırı ve her tasarımın "Tasarım Stoğu" hücresi elle güncellenebilir (✏️ gerçek stok); diğer tüm hücreler otomatik hesaplanır ve salt okunur (🔒 sitede görünen).
- **Ne almam lazım, ne bitmiş görürsün**: bir hücre 0'a inince kırmızı, 3 ve altına inince turuncu renkte vurgulanıyor — hem ortak beden stoğunda hem Tasarım Stoğu'nda. Panele her baktığında hangi bedeni/tasarımı yenilemen gerektiğini bir bakışta görürsün, ayrıca bir yere not almana gerek yok.

Servis kurulunca otomatik olarak senin ilk gönderdiğin tablo (9 tasarım, 5 beden) "Çocuk Tişört Bedenleri" adıyla seed edilmiş halde geliyor — sıfırdan girmene gerek yok.

`.env` içindeki `DRY_RUN=true` kaldığı sürece **Shopier'e hiçbir istek atılmaz** — sadece ne gönderileceği konsola loglanır. Sistemi Shopier'e bağlamadan önce güvenle deneyebilirsin.

## Shopier tarafında tamamlanması gereken 2 şey (değişmedi)

Shopier'in **Kişisel Erişim Anahtarı** ile çalışan resmi bir API'si var ve üçüncü parti araçlar (ör. Jet Stok) bunu kullanarak stokları güncelleyebiliyor — yani istediğin şey teknik olarak mümkün. Ama Shopier'in geliştirici portalı bu oturumdan taranamadığı için şu iki teknik detay hâlâ netleşmedi:

1. **Sipariş webhook'unun tam JSON şeması** (ürün id, varyasyon id, adet, sipariş no hangi alan adlarıyla geliyor).
2. **Stok güncelleme endpoint'i**: tam adres, HTTP metodu, gönderilecek JSON şeması.

**En pratik yol:** Servisi canlıya al (DRY_RUN açıkken zararsız), Shopier panelinden webhook adresini `https://<sunucu-adresin>/webhook/shopier-order` olarak tanımla, gerçek (ya da test) bir sipariş tetikle. Gelen ham veri otomatik olarak `data/webhook-debug.log`'a kaydediliyor — o dosyayı bana gönderirsen `src/shopierClient.js` içindeki iki TODO'yu tamamlarım. Bu ikisi netleşene kadar sistem hiçbir şeyi bozmaz — sadece Shopier'e yazmaz, her şeyi loglar. **Bu güncellemenin (çoklu tablo) bu kısıtla bir ilgisi yok** — tablo sistemi tamamen çalışır durumda, sadece gerçek Shopier'e otomatik yazma kısmı bu 2 bilgiyi bekliyor.

## Kurulum adımları (senin yapman gerekenler)

1. Shopier panelinde her tasarım-ürün için **ürün id**'sini ve her bedenin **varyasyon id**'sini bul (ürün düzenleme ekranındaki linkte/varyasyon listesinde görünür).
2. Bu servisin admin panelinde her tasarımın satırındaki **"🔗 Bağla"** butonuna tıkla, açılan pencereye Shopier Ürün ID'sini ve her bedenin Varyasyon ID'sini gir, Kaydet'e bas. (Artık API/komut satırı gerekmiyor — hepsi panelden.)
3. `.env` dosyasına `SHOPIER_ACCESS_KEY`'i gir.
4. Webhook/stok güncelleme detayları netleşince `SHOPIER_API_BASE`, `SHOPIER_STOCK_UPDATE_PATH` doldurulur, `src/shopierClient.js` tamamlanır.
5. `DRY_RUN=false` yapılır, canlıya alınır.

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
