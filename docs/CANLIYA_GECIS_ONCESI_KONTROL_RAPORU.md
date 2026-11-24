# Rafımda — Canlıya geçiş öncesi son teknik kontrol

Kapsam: depo kodu + sürüm paketi. **Canlı sunucuya bağlanılmadı, veritabanında işlem/migration yapılmadı.** Tüm testler SQLite/sahte DB üzerindedir; gerçek MySQL, gerçek FriSay çalışma zamanı ve gerçek theme4 çıktısı doğrulanmadı.

## 1. Sonuç
**Yüklemeye hazır mı?** Dosya olarak evet, ancak *koşullu*: (a) ilk kontrolde 2 hata bulunup düzeltildi (aşağıda), (b) hiçbir şey gerçek sunucuda denenmedi. Modülleri etkinleştirmeden önce yedek şart; ilk etkinleştirme sonrası ana sayfa/satıcı sayfaları sizin tarafınızdan gözle doğrulanmalı.

### Bu kontrolde bulunan ve düzeltilen hatalar
| # | Hata | Etki | Düzeltme |
|---|------|------|----------|
| 1 | `rafimda-theme` yönetici menü bağlantısı olmayan `design` grubuna kayıtlıydı | "Rafımda Tema" menüde **görünmezdi** | `system` grubuna alındı (menüde yalnızca general/sales/catalog/marketplace/system var) |
| 2 | Sürüm paketi canlı veriyi ezecek dosyalar içeriyordu: `img/` (logo, favicon, kategori görselleri), `data/`, `modules/theme4/assets/css/theme-settings.css` (panelde üretilen tema renkleri), kök `.htaccess`, `robots.txt` | 340072c / 81da628 paketini üzerine açtıysanız bu dosyalar **depodaki varsayılanlarla değişmiş olabilir** | Yeni pakette hariç tutuldu + test eklendi |

**Önceki paketi açtıysanız kontrol edin:** site logosu/favicon, kategori görselleri, tema renkleri (Tema Edit'ten kaydedip yenileyin), `.htaccess` ve `robots.txt` (yedekten geri yükleyin).

## 2. Bağımlılıklar
- Modüller arası **sert bağımlılık yok**: SQL'lerde FOREIGN KEY yok; PHP'de çapraz başvurular `class_exists(...)` ile koşullu (xml-hub/marketplace-hub/showcase → rafimda-vendor sınıfları varsa satıcı sahipliği kullanılır, yoksa yönetici modunda çalışır).
- `rafimda-theme` yalnızca `Module::isEnabled('rafimda-vendor')` bakar; kapalıysa satıcı bağlantısı üretmez.
- Çekirdek bağımlılıkları (kodla doğrulandı): `products` tablosunda `price, old_price, stock, active, desi, cargo_day` sütunları FriSay 3.0.2 `install/schema.sql`'de var. **Canlı DB eski bir FriSay sürümündense bu sütunların varlığını** `SHOW COLUMNS FROM products` ile doğrulayın (sorgu salt okunur).
- Zip bütünlüğü: 5 modülün tüm `require`, rota dosyası, şablon, CSS ve adaptör başvuruları pakette mevcut; pakette `env.php`/`.env`/döküm yok.

## 3. Kurulum sırası
Sıra teknik olarak zorunlu değil (24 olası sıra test edildi, hepsinde aynı 31 tablo çıktı). **Önerilen** sıra:
1. Yedek (dosya + DB dökümü)
2. `rafimda-theme` — DB şeması değişmez
3. `rafimda-vendor` — satıcı tabloları (+ 005_prefs: `rafimda_vendor_prefs`)
4. `rafimda-showcase`
4b. `rafimda-campaign` — `rc_*` (3 tablo; vendor+showcase sonrası; kapalıyken tüm entegrasyonlar eski davranışa döner)
5. `xml-hub`
6. `marketplace-hub`

## 4. Rota / modül yükleme uyumu (gerçek çekirdek koduyla okunarak)
- `index.php`: önce `container/front/<slug>.php`; yoksa `Module::resolveFrontRoute()` etkin modüllerin `$routes` listesine bakar. Çekirdekte `satici-*`, `xml-*`, `pazaryeri*` dosyası **yok** → çakışma yok. Modüller arasında aynı slug yok.
- Sınıf adı kuralı (`rafimda-vendor` → `RafimdaVendorModule`) ve `modül/modül.php` yapısı 5 modülde sağlanıyor.
- Rotalar yalnızca modül **kurulu + aktif** olunca çalışır; kurulmadan `/satici-giris` normal 404/kategori akışına düşer.

## 5. theme4 ↔ rafimda-theme
- Kullanılan kancalar (`home_slider`, `footer`, `mobile_menu`, `auth_register`) theme4 şablonlarında çiziliyor (`home.tpl`, `footer.tpl`, `plugin/left.tpl`, `register.tpl`) ve çekirdek kanca kataloğunda tanımlı. Şablon dosya adı çakışması yok (modül şablonları `modules/rafimda-theme/assets/templates/` altında, ayrı Smarty compile id'si).
- **Dikkat:** `slider` modülü de `home_slider` kullanır. İkisi etkinse çıktılar art arda eklenir (sıra: kanca konumu, eşitse modül adı alfabetik). Hero istemiyorsanız Rafımda Tema sayfasından kapatın.
- theme4 `home` kancası doluysa `home_slider` hiç çizilmez (theme4 `home.tpl` davranışı).
- Renkler `html:root` ile theme4 değişkenlerini ezer; **yönetici panelinde sonradan kaydedilen tema renkleri bu modül etkinken ezilir** (gerekirse modülü kapatın).
- Sayfa önbelleği: çıktı kullanıcıya bağlı değil.
- Varsayılan görünüm gerçek tarayıcıda **görülmedi**.

## 6. Tema ayarlarını kaydet/oku
Gerçek `Settings` (config/config.php), gerçek `ModuleBase` ve gerçek Smarty 5 ile (DB = SQLite): POST→kayıt→GET→formda geri okuma; ikinci kayıt UPDATE eder (satır çoğalmaz); yanlış CSRF ve demo modu yazmaz; HTML temizlenir/kaçışlanır; kapalı ayar çıktıyı kapatır — 14 test geçti. Gerçek yönetici sayfası/oturumu/MySQL ile denenmedi.

## 7. PHP uyumu, yol ve izin
- 61 modül/araç PHP dosyası `php -l` temiz (PHP 8.3). PHP 7.4'te **çalıştırılmadı**; PHP 8'e özgü sözdizimi (match, nullsafe, attributes, constructor promotion, union type, str_contains vb., parametrede sondaki virgül, değişkensiz catch) için tarama yapıldı, bulgu yok.
- Zip'te dosya 644 / dizin 755; yazılabilir olması gereken `cache/ logs/ tmp/ storage/ backups/` zip'te var (üzerine açınca mevcut izinler korunur). cPanel'de bu dizinlerin yazılabilir (755/775) olduğunu kontrol edin.
- `deploy/` web'den `.htaccess` ile reddedilir (Apache). Nginx kullanılıyorsa `deploy/staging/conf/nginx.staging.conf.snippet` içindeki deny kuralını ekleyin.

## 8. Canlı veritabanına yazan işlemler (AYRI RAPOR)
Kurulum = `Module::install()`: modülün `install()` + `modules` tablosuna satır + `module_display_hooks` satırları. **Silme/DROP/TRUNCATE/RENAME yok; CREATE TABLE IF NOT EXISTS ve eklemeli ALTER var.**

| Modül | Tablolar (yeni) | ALTER (mevcut tablo) | `settings` satırları | Not |
|---|---|---|---|---|
| rafimda-theme | yok | yok | yalnızca panelde Kaydet'e basınca `RAFIMDA_THEME_*` (4) | kanca satırları: home_slider, footer, mobile_menu, auth_register |
| rafimda-vendor | rafimda_migrations, rafimda_vendors, rafimda_vendor_staff, rafimda_vendor_products, rafimda_audit_log, rafimda_import_log, rafimda_vendor_orders, rafimda_vendor_order_items, rafimda_ledger, rafimda_payouts, rafimda_plans, rafimda_plan_log (12) | yalnızca kendi tabloları: `rafimda_vendor_products` +3 sütun, `rafimda_vendors` +`plan_expires_at` | `RAFIMDA_VENDOR_AUTO_APPROVE`=0 | **Çekirdek tablolar değişmez.** Kurulumdan sonra `order.placed` / `order.updated` kancalarına dinleyici bağlar (hatalar yutulur, sipariş akışı bozulmaz) |
| rafimda-showcase | rs_slots, rs_items | yok | yok | kanca: home_bottom |
| xml-hub | xh_migrations, xh_suppliers, xh_connections, xh_items, xh_runs, xh_errors, xh_price_history, xh_stock_history, xh_product_meta, xh_credentials, xh_changes, xh_export_feeds (12) | yok | `XML_HUB_SECRET`, `XML_HUB_CRON_KEY` (rastgele üretilir) | |
| marketplace-hub | mh_migrations, mh_accounts, mh_category_map, mh_listings, mh_errors (5) | yok | `MARKETPLACE_HUB_SECRET`, `MARKETPLACE_HUB_CRON_KEY` | |

**Kurulumdan sonra, kullanım sırasında** çekirdek tablolara yazılanlar (kurulumda değil): satıcı ürün oluşturma/güncelleme `Product::save` ve `products` (`price, stock, active`; XML Hub ayrıca `desi, cargo_day`) güncellemeleri — yalnızca satıcıya ait (sahiplik doğrulanmış) ürünlerde. Mevcut ürünlere satıcı atanmadıkça dokunulmaz. Ürün sahipliği `rafimda_vendor_products` tablosundadır.

Geri dönüş: modülü panelde devre dışı bırakmak veri silmez; tabloları silmek `uninstall.destructive.sql.manual` ile **elle** ve yedek sonrası yapılır (otomatik değil).

Migration'ları panel yerine kontrollü çalıştırmak isterseniz: `php deploy/live/bin/live-migrate.php` (varsayılan kuru çalıştırma; `--apply` yalnızca `RAFIMDA_BACKUP_CONFIRM=EVET-YEDEK-ALINDI` ile). Panelden kurulum ile bu yol aynı SQL'leri çalıştırır; ikisini birden kullanmayın.

## 9. Henüz çalışmayan / eksik
- Bayi / alt bayi yapısı yok.
- Amazon, Pazarama, Ozon bağdaştırıcıları iskelet düzeyinde (dosyalar var; gerçek API ile denenmedi). Trendyol/Hepsiburada/N11 de **gerçek hesapla denenmedi**.
- Pazaryerinden gelen siparişten ürün/sipariş oluşturma yok.
- Satıcı ödeme/muhasebe: defter ve ödeme talebi tabloları var; gerçek para transferi/banka entegrasyonu yok.
- Cron'lar (xml-cron, pazaryeri-cron) cPanel'de **elle tanımlanmalı**; tanımlanmadıkça senkron çalışmaz.
- Gerçek MySQL, gerçek sunucu, tarayıcıda görsel (mobil dahil) doğrulama yapılmadı.
- `FS_VERSION` değeri ve zip açılmadan önce yedek alınıp alınmadığı bilinmiyor.

## 10. Paket
Yeni paket (commit ve SHA-256 sohbet mesajında). Bu paket **güncelleme paketidir**: canlı görselleri/tema ayarlarını/.htaccess/robots.txt dosyalarını içermez.
