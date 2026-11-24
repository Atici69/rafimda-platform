# Hosting doğrulaması — sizin yapacağınız adımlar (tek tek)

Her adım **salt okunurdur**. Bir adımı bitirince sonucu bana yazın; ben onaylamadan sonrakine geçmeyin.
Parola, `env.php` içeriği, API anahtarı **paylaşmayın**. Sonuç etiketi: ÇALIŞIYOR / HATALI / BEKLİYOR.

| # | Adım | Ne yazacaksınız |
|---|------|-----------------|
| **1** | **Yedek** — cPanel → Backup → tam yedek (dosyalar + veritabanı) indirin; ayrıca phpMyAdmin → Dışa Aktar ile `.sql` alıp sunucuda ev dizinine koyun (bu dosyanın tam yolu ileride `RAFIMDA_BACKUP_FILE` olacak). | "Yedek alındı: evet/hayır, dosya boyutu" |
| 2 | **PHP sürümü** — cPanel → MultiPHP Manager (alan adınızın sürümü). SSH/Terminal varsa aşağıdaki komutlar. | Sürüm numarası |
| 3 | **Eklentiler** — Select PHP Version → Extensions. | `pdo_mysql, mbstring, zip, curl, gd, intl, openssl` açık/kapalı |
| 4 | **Erişim engelleri** — tarayıcıda şu adresleri açın: `/deploy/live/bin/live-preflight.php`, `/tools/api-test.php`, `/config/env.php`, `/core/`, `/logs/`. | Her biri için HTTP kodu (403/404 beklenir; 200 ise **hemen** bildirin) |
| 5 | **Preflight** — `docs/PREFLIGHT_CPANEL_REHBERI.md`. | Çıktı (parola yoksa kontrol edip) |
| 6 | **İki SQL** — phpMyAdmin: `SHOW COLUMNS FROM products;` ve `SELECT value FROM settings WHERE title = 'FS_VERSION';` | Sonuçlar |
| 7 | **Site gezintisi** — `docs/GERCEK_HOSTING_DOGRULAMA_KONTROL_LISTESI.md` §3 (form göndermeden). | Satır satır durum |

## PHP 8.1 doğrulama komutları (SSH/Terminal veya cPanel Terminal)
Hosting'de CLI ve web PHP sürümü farklı olabilir; ikisini de kontrol edin.
```bash
php -v                                   # CLI sürümü
php -r 'echo PHP_VERSION_ID >= 80100 ? "OK 8.1+\n" : "ESKI SURUM\n";'
ls /opt/cpanel | grep ea-php             # cPanel'de kurulu sürümler (ea-php81 vb.)
/opt/cpanel/ea-php81/root/usr/bin/php -v # web için seçtiğiniz sürümün CLI karşılığı (yol hostinge göre değişir)
cd ~/public_html
php -m | grep -Ei 'pdo_mysql|mbstring|zip|curl|gd|intl|openssl|json'
# Sözdizimi taraması (yalnızca okur): yalnızca hatalı satırları gösterir
find . -name '*.php' -not -path './libs/*' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v '^No syntax errors'
```
Web tarafındaki sürüm için sitenize **geçici phpinfo dosyası koymayın**; MultiPHP Manager ekranı yeterlidir.
Beklenen: son komut **hiç satır basmaz**. Satır basarsa dosya adını bana iletin.

## Apache ve nginx erişim kuralları (ayrı ayrı)
**Apache (.htaccess çalışır — `AllowOverride All` gerekir; cPanel'de varsayılan):**
- Kök `.htaccess`: `.git`, `logs`, `config`, `core`, `container`, `storage`, `lang`, `tools`, `backups`, `cache`, `upgrade` → 403.
- `deploy/` kök kuralında YOK; yalnızca `deploy/.htaccess` (`Require all denied`) korur. Yani **4. adımın sonucu önemli**.
- Staging'de `robots.staging.txt` için `deploy/staging/conf/.htaccess` tek dosyaya izin verir (bu makinede Apache olmadığından **doğrulanmadı**).

**nginx (.htaccess OKUNMAZ):** `nginx.conf.example` artık `deploy/` bloğunu da içerir; `tools/`, `config/`, `core/` zaten vardı. Canlı nginx yapılandırmanızda bu blokların **gerçekten** bulunduğunu hosting sağlayıcısından/dosyadan doğrulayın.

**Kanıt düzeyi:** Web'den araç çağrısının 404 vermesi PHP yerleşik sunucusuyla **gerçek HTTP** olarak test edildi (`deploy/staging/tests/run_web.php`); Apache/nginx kurallarının kendisi bu makinede **test edilemedi** (BEKLİYOR → 4. adım).

## staging_guard.php nasıl yüklenecek (kesin)
- Mekanizma: **`auto_prepend_file`**. Yalnızca **staging** sitesinde.
- Paylaşımlı hosting / PHP-FPM / FastCGI / LiteSpeed: staging docroot'una `.user.ini` (örnek: `deploy/staging/conf/staging.user.ini.example`, **mutlak yol**, etki ≤ 5 dk gecikmeli).
- Apache mod_php: `.htaccess` içine `php_value auto_prepend_file /mutlak/yol/staging_guard.php`.
- nginx + php-fpm: havuz dosyasına `php_admin_value[auto_prepend_file] = …`.
- **Canlı sitede ASLA bağlanmaz.** Bağlanmazsa web koruması yoktur (testle gösterildi); CLI araçları yine kendi bekçisiyle korunur.
- Gerçek `auto_prepend_file` yüklemesi PHP yerleşik sunucusuyla **test edildi**: staging DB → 200 + `X-Robots-Tag`, canlı DB adı → 503, canlı alan adı → 503.

## Staging DB (gerçek MySQL testleri için)
`deploy/staging/conf/staging-db.sql.example` (şablon, çalıştırılmadı) + `php deploy/staging/bin/staging-preflight.php` (salt okunur): kullanıcı yalnızca staging DB'ye yetkili mi, canlı DB görünüyor mu. Bu araç gerçek MySQL'de **henüz çalıştırılmadı** (BEKLİYOR). Migration/seed bundan sonra ve onayınızla.
