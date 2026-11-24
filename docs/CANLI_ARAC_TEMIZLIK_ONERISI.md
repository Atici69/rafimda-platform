# Canlıdan kaldırılması önerilen geliştirme araçları (ÖNERİ — hiçbir dosya silinmedi)

Dayanak: depoda `tools/*` dosyalarına çalışma zamanından (uygulama kodu, şablon, modül) **hiçbir çağrı yok**; yalnızca dokümanlarda ve birbirlerinin yorumlarında geçiyorlar. Hepsi Apache'te `tools/.htaccess` ve kök kuralıyla, nginx'te `nginx.conf.example` ile web'e kapalıdır, ancak sunucuda bulunmaları gereksiz saldırı yüzeyidir. Yayın paketi (`deploy/live/build-release.php`) bugün `tools/` dizinini **içeriyor**.

| Dosya | Kullanım noktası | Canlı için öneri |
|---|---|---|
| `tools/api-test.php` | Yerel Web API test paneli (HTTP'den çalışır; CLI kontrolü yok) | **Kaldır** (en riskli: web paneli) |
| `tools/api-product-example.php` | Örnek API istemcisi; çağıran yok | Kaldır |
| `tools/patch-admin-*.php`, `tools/patch-admin-i18n-batch2/3/4.php`, `tools/patch-admin-templates-i18n.php` | Tek seferlik kaynak yama betikleri (kod dosyalarını yeniden yazar) | **Kaldır** |
| `tools/merge-tr-batch2.php`, `tools/migrate-admin-lang.php`, `tools/sync-admin-lang.php` | Dil dosyası bakım betikleri; çağıran yok | Kaldır |
| `tools/xss-security-regression.php`, `tools/zip-security-regression.php` | Geliştirici regresyon testleri (CLI korumalı) | Kaldır (depoda kalır) |
| `tools/build-release.php` | Yükseltme paketi üretimi (`docs/UPGRADE.md`) | Kaldır (geliştirme makinesi aracı) |
| `tools/verify-install.php` | Kurulum dosyalarını denetler | Kurulum bitince kaldır |
| `tools/export-schema.php` | Şema dışa aktarımı; **DB'ye bağlanıp env.php okur** (CLI korumalı yapıldı) | Kaldır; gerekirse geçici kullanın |
| `tools/.htaccess` | Dizini kapatır | Dizin silinirse gerekmez |

**Önerilen yol (siz onaylayana kadar uygulanmaz):**
1. Hosting'de önce yedek; sonra `tools/` dizinini **silmek yerine** `~/_to_delete_tools/` altına taşıyın (geri alınabilir).
2. Siteyi gezip hata olmadığını doğrulayın (araçlara çalışma zamanı bağımlılığı yok, ama doğrulama sizde).
3. Kalıcı çözüm olarak `deploy/live/build-release.php` dışlama listesine `tools/**` eklenir (ayrı onayla; mevcut testler güncellenir).
4. `deploy/` dizini canlıda **kalmalı** (live-preflight/migrate/verify için); işiniz bitince aynı yöntemle taşınabilir.
