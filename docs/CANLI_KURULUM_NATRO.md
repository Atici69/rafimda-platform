# Rafımda Canlı Kurulum Kılavuzu — rafimda.com.tr (Natro cPanel)

> **Durum:** Bu kılavuz ve araçlar hazırlıktır. Bu oturumun Natro hesabına, cPanel'e, MySQL'e veya rafimda.com.tr'ye **erişimi yoktur** (ağ engelli). Aşağıda "kuruldu/çalışıyor" denilen hiçbir şey yoktur; canlıda hiçbir işlem yapılmadı. Rafımda modülleri **hiçbir zaman gerçek MySQL'de çalıştırılmadı** (yalnızca SQLite + stub testleri). Canlıya doğrudan almak bu riski taşır; bu yüzden her adım **yedek + dry-run + doğrulama** ile korunmuştur.

## 1. İnceleme bulguları (depo ve paket)
| Konu | Bulgu |
|---|---|
| Depo | `Atici69/rafimda-platform` `main` — son commit bu kılavuzun commit'idir (`git log -1`). Yerel `origin` hâlâ `bera65/frisay`'ı gösterir: **ona push yapılmaz**; gönderim `rafimda` adlı uzak depodan yapılır. |
| PHP / DB | PHP ≥ 7.4, MySQL ≥ 5.7 / MariaDB, Apache `mod_rewrite`; eklentiler `pdo_mysql, mbstring, gd` (README) + Rafımda için `sodium, curl, json` (zorunlu), `xmlreader, xmlwriter, dom` (önerilir). **Composer/npm bağımlılığı yoktur.** |
| Kurulum | FriSay'ın `install/` sihirbazı; **`config/env.php` varsa sihirbaz kendini kapatır** (mevcut kurulumu yeniden kurmaz). |
| `deploy/staging` | Canlı kurulum dosyası **değildir**: araçları `RAFIMDA_ENV=staging` ve DB adında test/staging ister, canlıda bilerek reddeder; `staging-seed` TEST verisi yazar (canlıda KULLANILMAZ). Canlıya uygun olan yalnızca ortak kütüphaneler (`deploy/staging/lib`) ve doğrulayıcı mantığıdır; bunlar `deploy/live/` araçlarınca yeniden kullanılır. |
| Yeni | `deploy/live/`: salt-okunur ön kontrol, **dry-run varsayılanlı** migration, salt-okunur doğrulama, sürüm paketi üretici. `deploy/.htaccess` tüm `deploy/` dizinini web'e kapatır. |
| **HSTS uyarısı** | `APP_ENV=production` iken FriSay `Strict-Transport-Security: max-age=31536000; includeSubDomains` gönderir. Ana alanda bu, **tüm alt alanları** (mail., cpanel., webmail., test. …) bir yıl boyunca tarayıcıda HTTPS'e zorlar; geçerli sertifikası olmayan alt alan açılmaz. Önce tüm alt alanların HTTPS'ini doğrulayın veya kurulumdan sonra panelden `SECURITY_HSTS` = `off` yapın; ancak her şey doğrulanınca `auto/on`. |
| Gizli anahtarlar | `XML_HUB_SECRET`, `MARKETPLACE_HUB_SECRET`, `XML_HUB_CRON_KEY` DB'de (`settings`); **DB yedeği bunları taşır** → yedeği web kökü ve git dışında, erişimi kısıtlı saklayın. |
| E-posta | `MAIL_DRIVER=php` gerçek e-posta gönderir. Canlıda bu **istenen** davranıştır ama test işlemleri gerçek müşteriye e-posta atabilir; test için gerçek adres kullanmayın. |

## 2. Natro cPanel'de sizin kontrol edeceğiniz noktalar (doğrulanmadı)
1. **MultiPHP Manager:** alanın PHP sürümü ≥ 7.4 (tercihen 8.1+); *PHP Selector/MultiPHP INI Editor:* `sodium, curl, mbstring, gd, pdo_mysql, xmlreader, xmlwriter, dom` etkin; `disable_functions` içinde `exec`/`shell_exec` olması modülleri bozmaz (yalnızca sürüm paketi aracı yerelde çalışır). **PHP sürümünü sizin onayınız olmadan değiştirmem.**
2. **MySQL Databases:** `rafimda_…` veritabanı + kullanıcı (ALL PRIVILEGES). Sürüm ve `sql_mode` ön kontrolde görünür.
3. **SSL:** AutoSSL/Let's Encrypt ana alan + `www` için etkin; HTTP→HTTPS yönlendirmesi.
4. **Document root:** genelde `public_html/`. Uygulama alt klasördeyse `.htaccess` içindeki `RewriteBase /` ve `ADMIN_URI` ayarını uyarlayın.
5. **İzinler:** klasörler 755, dosyalar 644; `config/env.php` **600** (veya 640); yazılabilir: `cache/ logs/ tmp/ img/products/ storage/`.
6. **Terminal/SSH var mı?** Varsa §5 komutları doğrudan; yoksa cPanel *Cron Jobs* ile tek seferlik çalıştırıp çıktıyı dosyaya yönlendirin (§5, not).
7. **LiteSpeed/Apache:** `.htaccess` kuralları (`RewriteRule … [F,L]` engelleri, `deploy/.htaccess`) geçerli olmalı; `https://rafimda.com.tr/config/env.php`, `/deploy/live/bin/live-migrate.php`, `/modules/xml-hub/xml-hub.php`, `/install/schema.sql` adreslerinin **403/404** verdiğini tarayıcıdan doğrulayın.

## 3. Yükleme paketi
Yerelde (veya bu depoyu klonladığınız yerde):
```bash
php deploy/live/build-release.php /yol/cikti
```
Çıktı: `rafimda-release-<commit>.zip` + **commit kimliği ve SHA-256**. Paket `git archive` ile üretilir: yalnızca git'te izlenen dosyalar (**`config/env.php`, `.env`, yedek, log, `.git` yok**), `docs/` ve test dizinleri hariç. Hangi commit'in yüklendiğini bu çıktı kanıtlar; yüklemeden sonra raporlayın.

## 4. Kurulum sırası (her adımın sonunda durun ve doğrulayın)
**0. Yedek (zorunlu):** cPanel → *Backup* (tam/ev dizini) **ve** phpMyAdmin → Dışa Aktar (SQL). Yedeği sunucu dışına indirin; `public_html/` içinde bırakmayın.
**1. Mevcut durum:** cPanel File Manager'da `public_html/` içeriğini ve varsa `config/env.php`'nin varlığını not edin. **Mevcut `config/env.php`'yi ve `img/products/`, `storage/` içeriğini asla silmeyin/üzerine yazmayın.**
**2. Yükleme:** zip'i `public_html/`'a yükleyip *Extract* edin. Zip'te `config/env.php` olmadığından mevcut `env.php` korunur; mevcut dosyaların üzerine yazılmasını (aynı yoldaki çekirdek dosyalar) önce yedekten karşılaştırın. **Toplu silme yapmayın.**
**3a. Yeni kurulumsa (env.php yok):** `https://rafimda.com.tr/install/` sihirbazını boş veritabanıyla çalıştırın (sihirbaz şemayı, ayarları ve yönetici hesabını kurar; `APP_ENV=production` yazar). Veritabanının **gerçekten boş** olduğunu phpMyAdmin'den doğrulayın; sihirbaz tabloları oluşturur — dolu bir veritabanına karşı çalıştırmayın.
**3b. Mevcut FriSay varsa (env.php var):** sihirbaz çalışmaz; doğrudan 4'e geçin.
**4. Ön kontrol (salt-okunur):**
```bash
cd ~/public_html
export RAFIMDA_ENV=live RAFIMDA_LIVE_CONFIRM=EVET-CANLI-DB RAFIMDA_LIVE_DB_NAME=<config/env.php içindeki DB_NAME>
php deploy/live/bin/live-preflight.php
```
DB bilgisi komut satırına yazılmaz; araç `config/env.php`'yi okur. Mevcut kayıt **sayılarını** gösterir (içerik değil); veritabanı doluysa uyarır ama hiçbir şeyi sıfırlamaz.
**5. Migration planı (dry-run, varsayılan):**
```bash
php deploy/live/bin/live-migrate.php
```
Ne ekleneceğini listeler, **hiçbir şey yazmaz**.
**6. Uygulama (yedekten sonra):**
```bash
export RAFIMDA_BACKUP_CONFIRM=EVET-YEDEK-ALINDI
export RAFIMDA_MIGRATE_CONFIRM=EVET-MIGRATION      # migration için ayrı onay (modül kurulumu için RAFIMDA_MODULE_CONFIRM=EVET-MODUL-KURULUMU ayrıdır)
export RAFIMDA_BACKUP_FILE=/home/KULLANICI/yedek.sql   # ZORUNLU: dosya var, en az 1 KB ve 24 saatten yeni olmalı
php deploy/live/bin/live-migrate.php --apply
```
Canlı araçlar şunları da reddeder: `staging/stg/test` içeren veritabanı adı, `RAFIMDA_STAGING_CONFIRM` tanımlı (karışık oturum), staging site adresi, web (HTTP) üzerinden çalıştırma. Hata mesajlarında DB parolası ve kullanıcı adı `***` ile maskelenir.
Yalnızca `CREATE TABLE IF NOT EXISTS` ve korumalı `ADD COLUMN`; `DROP/TRUNCATE/DELETE/RENAME` reddedilir; FriSay çekirdek tablolarına **dokunmaz**; ilk hatada durur.
**7. Doğrulama (salt-okunur):** `php deploy/live/bin/live-verify.php --out=logs/live-verify.md` → 0 FAIL beklenir (gerçek MySQL'de ilk çalıştırma; bir FAIL çıkarsa çıktıyı iletin).
**8. Modülleri etkinleştirin:** Yönetici paneli → Modüller → `rafimda-vendor`, `xml-hub`, `marketplace-hub`, `rafimda-showcase` (modül kaydı ve ayar tohumlama — ör. ana komisyon, cron anahtarı, varsayılan paketler — yalnızca panelden kurulumda yapılır).
**9. Sertleştirme:** `install/` klasörünü **sizin onayınızla** yeniden adlandırın/silin (sihirbaz env.php varken kendini kapatsa da gereksiz); `SECURITY_HSTS` kararını verin (§1); yönetici URI'sini (`ADMIN_URI`) varsayılan `admin`dan değiştirin; `config/env.php` izni 600; cron (§5) ekleyin.

## 5. Cron (yalnızca siz eklersiniz; eklenmedi)
cPanel → Cron Jobs (dakikalık):
```
* * * * * curl -s "https://rafimda.com.tr/xml-cron?q=cron&key=ANAHTAR" > /dev/null 2>&1
* * * * * curl -s "https://rafimda.com.tr/pazaryeri-cron?q=cron&key=ANAHTAR" > /dev/null 2>&1
```
`ANAHTAR` panelde (XML Hub → Cron) görünür; sohbete/ticket'a yazmayın. *Not:* SSH yoksa §4'teki `php …` komutlarını da tek seferlik cron olarak `… > /home/KULLANICI/live-out.txt 2>&1` ile çalıştırıp çıktıyı File Manager'dan okuyun, sonra cron'u silin; ortam değişkenlerini aynı satırın başına `RAFIMDA_ENV=live … php …` biçiminde verin.

## 6. Canlıda çalıştırılacak test listesi (kullanıcıyla yapılır)
Ana sayfa · yönetici girişi · satıcı kaydı (kendi test hesabınızla) ve onay · ürün ekleme/düzenleme/silme · stok/fiyat · müşteri/sipariş (yalnızca **kendinize** ait test siparişi, gerçek müşteriye işlem yok) · XML içe aktarma · abonelik/paket atama · bir satıcıyla diğerinin ürün/sipariş adresine erişim denemesi (reddedilmeli) · CSRF'siz POST reddi · oturum çerezi bayrakları (Secure/HttpOnly/SameSite).
**Yapılandırılmamış olarak kalacaklar** (gerçek anahtar yoksa çalışıyor gösterilmez): ödeme sağlayıcıları, SMS, SMTP, Trendyol/Hepsiburada/N11 hesapları (yalnızca stok/fiyat + bağlantı testi var, canlı doğrulanmadı), Amazon/Pazarama/Ozon (geliştirilmedi).

## 7. Geri dönüş (etkilenen dosya ve tablolar)
- **Dosyalar:** zip'ten çıkan her şey (`modules/{rafimda-vendor,xml-hub,marketplace-hub,rafimda-showcase}/`, `deploy/`, `docs`-hariç; çekirdekte `.gitignore`, `README` gibi sürüm farkları). Geri dönüş: adım 0'daki cPanel yedeğinden bu dizinleri geri yükleyin.
- **Tablolar (yalnızca eklenen, mevcut tablolara dokunulmaz):**
  - rafimda-vendor: `rafimda_migrations, rafimda_vendors, rafimda_vendor_staff, rafimda_vendor_products, rafimda_audit_log, rafimda_import_log, rafimda_vendor_orders, rafimda_vendor_order_items, rafimda_ledger, rafimda_payouts, rafimda_plans, rafimda_plan_log`
  - xml-hub: `xh_migrations, xh_suppliers, xh_connections, xh_items, xh_runs, xh_errors, xh_price_history, xh_stock_history, xh_product_meta, xh_credentials, xh_changes, xh_export_feeds`
  - marketplace-hub: `mh_migrations, mh_accounts, mh_category_map, mh_listings, mh_errors`
  - rafimda-showcase: `rs_slots, rs_items`
  - `rafimda_vendors`'a eklenen sütun: `plan_expires_at`; `rafimda_vendor_products`'a: `moderation, moderation_note, moderated_at`.
  - Panel kurulumu ayrıca `settings` tablosuna ayar satırları ve `modules`/`module_display_hooks` kayıtları ekler.
- Tabloları **silmek** yıkıcıdır; yalnızca sizin açık onayınızla, yedek alındıktan sonra yapılır (araçlar bunu yapmaz). Çoğu durumda modülü panelden devre dışı bırakmak yeterlidir (veri korunur).

## 8. Bu oturumda kanıtlananlar ve kanıtlanmayanlar
| | |
|---|---|
| Kanıtlı (SQLite/stub ile) | Modül mantığı, migration idempotansı/durma davranışı, koruma kuralları, paket içeriği (env/yedek/docs yok), seed izolasyonu (TEST) |
| **Kanıtlanmadı** | Gerçek MySQL'de migration, FriSay kurulum sihirbazı, gerçek oturum/CSRF, Natro ortamı, SSL, cron, tarayıcı, canlı pazaryeri/ödeme/SMS/e-posta |
