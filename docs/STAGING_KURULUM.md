# Rafımda Staging Kurulum Kılavuzu (test.rafimda.com.tr)

> **Durum:** Bu paket SQLite + MySQL→SQLite çevirisiyle test edildi (bkz. §9). **Hiçbir adım gerçek MySQL'de, gerçek sunucuda veya gerçek FriSay açılışıyla çalıştırılmadı.** Aşağıdaki "beklenen" sonuçlar tasarım beklentisidir, kanıt değildir. İlk gerçek çalıştırmada çıkan hataları (komut çıktısıyla) iletin.

Paket: `deploy/staging/` (çekirdeğe dokunmaz). Tüm araçlar yalnızca komut satırından çalışır ve **güvenlik bekçisi** (`StagingGuard`) geçmeden veritabanına bağlanmaz.

## 1. Önkoşullar
- PHP ≥ 7.4 (CLI ve web aynı sürüm); eklentiler: `pdo_mysql, mbstring, json, curl, sodium` (zorunlu), `xmlreader, xmlwriter, dom, gd` (önerilir).
- MySQL 5.7+/MariaDB 10.3+ — **canlıdan tamamen ayrı** boş bir veritabanı ve kullanıcı.
- DNS: `test.rafimda.com.tr` → staging sunucusu; HTTPS sertifikası.
- Dizinler yazılabilir: `cache/ logs/ tmp/` (+ `img/products/`).

## 2. Kodu alın
```bash
git clone https://github.com/Atici69/rafimda-platform.git /var/www/rafimda-staging
cd /var/www/rafimda-staging
```

## 3. Veritabanı ve ortam değişkenleri
Staging veritabanını ve kullanıcısını **siz** oluşturun (araçlar veritabanı oluşturmaz/silmez). Adı mutlaka `staging`, `stg` veya `test` içermelidir:
```sql
CREATE DATABASE rafimda_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rafimda_staging_user'@'localhost' IDENTIFIED BY '<güçlü-parola>';
GRANT ALL PRIVILEGES ON rafimda_staging.* TO 'rafimda_staging_user'@'localhost';
```
Ortam değişkenlerini repo **dışında**, `600` izinli bir dosyada tutun (şablon: `deploy/staging/conf/env.staging.example`):
```bash
sudo install -m 600 /dev/null /etc/rafimda/staging.env   # sonra düzenleyin
set -a; . /etc/rafimda/staging.env; set +a
```
| Değişken | Anlamı |
|---|---|
| `RAFIMDA_ENV` | `staging` olmalı |
| `RAFIMDA_STAGING_CONFIRM` | `EVET-STAGING-DB` |
| `RAFIMDA_PROD_DB_NAME` | **Canlı** DB'nin adı; `DB_NAME` bununla aynıysa araçlar reddeder |
| `DB_HOST` `DB_NAME` `DB_USER` `DB_PASS` | Staging veritabanı (port: yalnız 3306; FriSay bağlantısı portu desteklemez) |
| `RAFIMDA_SITE_URL` | `https://test.…` (isteğe bağlı ama önerilir; staging dışı adresi reddeder) |
| `RAFIMDA_ADMIN_URI` | İsteğe bağlı yönetim yolu (boşsa rastgele) |

Bekçi şunları reddeder: `RAFIMDA_ENV≠staging`, onay ifadesi yok, DB adı canlıyla aynı, DB adı staging/test içermiyor, site adresi `test./staging./stg.` ile başlamıyor.

## 4. FriSay çekirdeğini kurun
FriSay açık kurulum modeli: `config/env.php` varlığı = "kurulu". İki yol:

**A (önerilen, FriSay'ın kendi yolu):** `https://test.rafimda.com.tr/install/` sihirbazını staging veritabanıyla çalıştırın (şema, ayarlar ve yönetici hesabını o oluşturur). Sihirbaz `config/env.php` yazar (`APP_ENV=production`; staging için doğru — FriSay hata gizleme/Secure çerez/HSTS'yi yalnızca production'da uygular).

**B (betikle, yönetici hesabı sizde):** `php deploy/staging/bin/staging-env.php` (mevcut `env.php`'nin üzerine yazmaz, 0600 yazar) ve `install/schema.sql` içe aktarımı; yönetici hesabını ayrıca oluşturmanız gerekir. *Bu yol doğrulanmadı; A önerilir.*

## 5. Migration'ları çalıştırın
```bash
php deploy/staging/bin/staging-migrate.php --dry-run   # ne çalışacağını gösterir, yazmaz
php deploy/staging/bin/staging-migrate.php             # sırayla uygular
```
Sıra: `rafimda-vendor` (001–004) → `xml-hub` (001–003) → `marketplace-hub` (001) → `rafimda-showcase` (install.sql). Her adım `OK / SKIP / FAIL` raporlanır; **ilk hatada durur** (çıkış kodu 1), başarısız migration "uygulandı" olarak kaydedilmez, `DROP/TRUNCATE/DELETE/RENAME` içeren dosyalar reddedilir. Hatayı giderip yeniden çalıştırmak güvenlidir (idempotent; zaten var olan `ADD COLUMN` atlanır).
Modüllerle aynı izleme tablolarını (`rafimda_migrations`, `xh_migrations`, `mh_migrations`) kullanır; bu yüzden panelden modül kurulumu sonradan bunları tekrar çalıştırmaz.

## 6. Modülleri panelde etkinleştirin
Yönetim paneli → Modüller → `rafimda-vendor`, `xml-hub`, `marketplace-hub`, `rafimda-showcase` kur/etkinleştir (modül kaydı `modules` tablosuna panelden yapılır; migration betiği bunu yapmaz). Tema'ya `{$hooks.home_showcase}` kancası eklenmedikçe ana sayfa vitrin alanları görünmez.

## 7. Doğrulayın
```bash
php deploy/staging/bin/staging-verify.php --out=logs/staging-verify.md
```
Kontroller: PHP sürüm/eklentiler, çekirdek dosyalar ve yazılabilir dizinler, `config/env.php` (DB uyumu, APP_ENV, APP_DEBUG), çekirdek tablolar, modüllerin **tüm tabloları, sütunları ve indeksleri** (migration dosyalarından türetilir), bekleyen/yetim migration kayıtları, `robots.staging.txt`. Çıkış kodu 1 = en az bir FAIL. `product_variations` tablosu çekirdek tarafından ilk kullanımda oluşturulur (yoksa uyarı).

## 8. Test verisini yükleyin (isteğe bağlı)
```bash
php deploy/staging/bin/staging-seed.php
```
Yalnızca **ekler** (silmez/güncellemez), tekrar çalıştırılabilir. Üç satıcı (`TEST Mağaza A/B/C`), her birine 4 ürün, 3 müşteri, 3 sipariş (A+B, B+C, A), satıcı C için 5 ürün limitli `test-sinirli` paketi. Tüm kayıtlar işaretli: e-posta `@test.rafimda.invalid`, telefon `TEST-…`, ürün kodu `TEST-…`, sipariş `TEST-…`. Çalıştırdıktan sonra otomatik denetler: satıcılar arası ürün/sipariş/müşteri erişimi, komisyon = oran × ara toplam, bölünmüş toplam = kalem toplamı, kota. `TEST` olmayan sipariş içeren veritabanında çalışmayı **reddeder**. Kullanıcılara kullanılabilir parola atanmaz; giriş testi için parolayı panelden belirleyin. E-posta/SMS/ödeme/pazaryeri çağrısı yapmaz.

## 9. Arama motoru ve erişim koruması
- Nginx: `deploy/staging/conf/nginx.staging.conf.snippet` (X-Robots-Tag, tam engel robots.txt, Basic Auth, ayrı loglar). Apache: `apache.staging.htaccess.snippet`.
- İsteğe bağlı son savunma: `auto_prepend_file = …/deploy/staging/conf/staging_guard.php` — `X-Robots-Tag` ekler ve `env.php` DB adı staging gibi değilse isteği 503 ile durdurur.
- Basic Auth açıkken cron uçlarını (`/xml-cron`, `/pazaryeri-cron`) muaf tutun; anahtar (`key=`) zorunlu kalır.

### Gizli anahtarlar
- Repoda/ortam dosyasında gerçek değer yok; `.gitignore` `config/env.php`'yi dışlar.
- `XML_HUB_SECRET` ve `MARKETPLACE_HUB_SECRET` ilk kullanımda **otomatik üretilip `settings` tablosuna** yazılır (pazaryeri/XML kimlik bilgilerini şifreler). Staging veritabanı yedeği bu anahtarı da içerir; veritabanı yedeği ve anahtar aynı yerde saklanırsa şifreleme koruması zayıflar — canlıda anahtarı ayrı yedekleyin.
- Cron anahtarı panelden üretilir; sohbete/repoya yazmayın.

## 10. Cron (sunucuda sizin kuracağınız; kurulmadı)
```
* * * * * curl -s -u KULLANICI:PAROLA "https://test.rafimda.com.tr/xml-cron?q=cron&key=ANAHTAR"       >> /var/log/rafimda/xml-cron.log 2>&1
* * * * * curl -s -u KULLANICI:PAROLA "https://test.rafimda.com.tr/pazaryeri-cron?q=cron&key=ANAHTAR" >> /var/log/rafimda/mh-cron.log 2>&1
```
(Basic Auth muafiyeti verdiyseniz `-u` gerekmez.)

## 11. Hata olursa hangi loglar
| Sorun | Bakılacak yer |
|---|---|
| Migration/verify/seed çıktısı | Komutun kendi çıktısı (`2>&1 \| tee logs/staging-run.log`) |
| PHP hata/uyarı | `logs/php-error.log` (FriSay), PHP-FPM log |
| Web | `/var/log/nginx/rafimda-staging.{access,error}.log` |
| MySQL | MySQL error log; migration hatalarında SQL hatası komut çıktısında |
| XML Hub | Panel → XML Hub → Günlük / Hatalar; cron logu |
| Pazaryeri | Panel → Pazaryeri → Hatalar |
| 503 "Staging koruması" | `config/env.php` DB_NAME staging gibi değil |

## 12. Kurulumdan sonra yapılacak testler (kontrol listesi)
1. `staging-verify.php` → 0 FAIL. 2. `curl -I https://test…/` → `X-Robots-Tag: noindex…`; `/robots.txt` → `Disallow: /`.
3. Yönetici girişi (admin yolu), CSRF'li bir formun reddi/kabulü, oturum çıkışı.
4. Üç TEST satıcısıyla panel: ürün ekle/düzenle/sil, stok/fiyat, müşteri, sipariş, abonelik; **bir satıcıyla diğerinin ürün/sipariş URL'sine (ID değiştirerek) erişim denemesi → reddedilmeli**.
5. Gerçek `Product::save` ile ürün ekleme (modül testleri bunu taklit ediyordu) ve varyantlı ürün.
6. Gerçek siparişle (test ödeme yöntemiyle) `RvOrders::splitOrder` kancası tetikleniyor mu; sipariş durumu değişince komisyon/ledger tutarlı mı.
7. XML: URL ve dosyadan içe aktarma, zamanlanmış çalıştırma (cron logu), `/xml-export?t=…` besleme.
8. Pazaryeri: yalnızca **sandbox/mock** hesapla bağlantı testi; gerçek pazaryerine gönderim yapmayın.
9. Cron ve kuyruk: iş tetikleyip log/kuyruk tablosunda ilerlemeyi doğrulayın; bilerek hatalı kaynakla yeniden deneme davranışı.
10. Tarayıcıda mobil/tablet/masaüstü (375/768/1280) dolaşımı; ana sayfa vitrin kancası.
11. Güvenlik: SQLi/XSS giriş denemeleri, yetkisiz erişim, dosya yükleme, oturum çerezi bayrakları (Secure/HttpOnly/SameSite).

## 13. Otomatik testler (bu pakete ait)
```bash
php deploy/staging/tests/run.php        # koruma, SQL ayrıştırma, migrator, doğrulayıcı, hijyen
php deploy/staging/tests/run_seed.php   # gerçek çekirdek şeması (SQLite çevirisi) + modül sınıfları üzerinde seed
```
Bunlar **MySQL'de çalışmaz ve MySQL'i kanıtlamaz**; yalnızca mantığı (ör. hatada durma, sürüm kaydı, ALTER idempotansı, izolasyon kontrollerinin gerçekten yakalayıcı olması) doğrular.

## 14. Bilinen sınırlar / doğrulanmamış noktalar
- `staging-seed.php` gerçek `config/settings.php` açılışını (oturum, `Performance::ensureDefaults`, CLI'de başlık/oturum davranışı) kullanır; CLI'de çalıştığı **doğrulanmadı**. Takılırsa çıktıyı iletin.
- MySQL katı mod (STRICT_TRANS_TABLES) altında seed ve migration'lar denenmedi.
- `staging-env.php` yolu (B) ve `staging_guard.php` auto-prepend gerçek sunucuda denenmedi.
- FriSay kurulum sihirbazının `APP_ENV`/`ADMIN_URI` çıktısı sizin kurulumunuzda doğrulanmalı.
