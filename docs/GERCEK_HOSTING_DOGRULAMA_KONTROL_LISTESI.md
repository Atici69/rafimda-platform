# Gerçek hosting doğrulaması — VERİ DEĞİŞTİRMEYEN kontrol listesi

Sürüm: ca9621f üzerine. Bu liste yalnızca **okuma** yapar. Hiçbir adım migration, seed, modül kurulumu, silme veya güncelleme içermez.
Her adımda sonucu **ÇALIŞIYOR / HATALI / BEKLİYOR** olarak not edin; denenmeyen adıma PASS yazmayın.

## 0. Başlamadan
- [ ] Hosting panelinden **tam yedek** (dosyalar + veritabanı) alındı; yedek dosyası bilgisayarınıza indirildi.
- [ ] Zip'in SHA-256 değeri, bu depodaki sürüm bilgisiyle aynı (`sha256sum` / panel hash aracı).
- [ ] Bu adımlarda **gerçek müşteriye** sipariş/e-posta/SMS tetikleyen hiçbir işlem yapılmayacak.

## 1. Ortam (salt okunur)
1. cPanel → **MultiPHP Manager**: alan adının PHP sürümü yazın. (Hedef ≥ 8.1; kod 8.1 için statik tarandı, **8.1'de çalıştırılmadı**.)
2. cPanel → **Select PHP Version / Extensions**: `pdo_mysql`, `mbstring`, `intl` (varsa), `zip`, `curl`, `gd` açık mı?
3. `config/env.php` izni 600/640 mı? `APP_ENV=production`, `APP_DEBUG` kapalı mı? (dosyayı **açıp parolaları paylaşmayın**; yalnızca bu iki anahtarı kontrol edin.)
4. `deploy/`, `tools/`, `config/`, `core/`, `logs/` adreslerini tarayıcıdan açmayı deneyin → hepsi **403/404** olmalı (örn. `https://alanadiniz/deploy/live/bin/live-preflight.php`).
   - nginx kullanıyorsanız `.htaccess` işlemez; `deploy/` ve `tools/` için `location ^~ … { deny all; }` ekli mi bakın.

## 2. Preflight (SELECT-only)
`docs/PREFLIGHT_CPANEL_REHBERI.md` adımlarıyla `live-preflight.php`. Beklenen: çekirdek tablolar var, kayıt **sayıları** görünür, hiçbir şey yazılmaz. Çıktıda parola/anahtar olmamalı; çıktıyı paylaşmadan önce kontrol edin.
Ek olarak phpMyAdmin'de yalnızca şu iki SELECT/SHOW:
```sql
SHOW COLUMNS FROM products;
SELECT value FROM settings WHERE title = 'FS_VERSION';
```

## 3. Siteyi yalnızca gezme (veri üretmeyen)
- [ ] Ana sayfa açılıyor, logo/favicon/renkler doğru.
- [ ] Bir kategori sayfası, bir ürün sayfası açılıyor (sepete **eklemeyin**).
- [ ] `/login`, `/register`, `/forgot-password`, `/contact`, bir CMS sayfası **açılıyor** (formları göndermeyin).
- [ ] Yönetici girişi sayfası açılıyor (giriş yapmak serbest; ayar kaydetmeyin).
- [ ] Mobil görünüm (telefondan) bozuk değil.
- [ ] Kaynak sayfada `Fatal`, `Warning`, `SQLSTATE`, dosya yolu görünmüyor.

## 4. Güvenlik başlıkları (salt okunur)
- [ ] https yönlendirmesi çalışıyor; çerezler Secure/HttpOnly (tarayıcı geliştirici araçları → Application).
- [ ] `https://alanadiniz/config/env.php`, `/.git/`, `/backups/` → erişilemiyor.

## 5. Bu aşamada YAPILMAYACAKLAR
`live-migrate.php --apply`, modül etkinleştirme/kurulum, ayar kaydetme, ürün/sipariş/kullanıcı ekleme-silme, XML içe aktarma, pazaryeri senkronu, cron ekleme.
Bunlar için ayrı yazılı "devam" onayı gerekir.

## 6. Bana iletilecekler (parola/anahtar HARİÇ)
PHP sürümü · eklenti durumu · preflight çıktısı · iki SQL sonucu · 4. bölümdeki 403/404 sonuçları · her satırın ÇALIŞIYOR/HATALI/BEKLİYOR durumu.

## Gerçek MySQL gerektiren testler
Yalnızca **ayrı staging veritabanında** (`RAFIMDA_ENV=staging`, DB adı `staging|stg|test` içerir, `StagingGuard` onayıyla) çalıştırılır: `staging-migrate.php`, `staging-seed.php`, `staging-verify.php`. Canlı veritabanında çalıştırılmaz; `LiveGuard` staging/test adlı DB'yi, `StagingGuard` canlı adlı DB'yi reddeder.

## Eksik: canlıda modül kurulumu (kurulum YAPILMAZ)
Şu an canlıda modül kaydı (`modules` satırı, ayar tohumlama, görüntü kancaları) yalnızca yönetici panelinden yapılır; canlı için komut satırı karşılığı **yoktur**. Tamamlanması gereken mimari: (1) `live-module-plan.php` salt-okunur plan (hangi satırlar/ayarlar yazılacak), (2) `--apply` için `LiveGuard::check(..., 'module-install')` (yedek dosyası + `RAFIMDA_MODULE_CONFIRM`), (3) ilgili modülün `install()` kodunun çekirdek açılışı gerektirmesi nedeniyle bekçi sonrası kontrollü açılış, (4) her modül için geri alma notu. Bu yazılıp test edilmeden panelden de kurulum yapmayın.
