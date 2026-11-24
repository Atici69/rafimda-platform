# Natro — canlıya geçiş hazırlığı, yedek güvenliği ve geri dönüş planı

Durum: **hazırlık**. Canlı veritabanına/sunucuya bağlanılmadı; hiçbir migration, seed veya modül kurulumu çalıştırılmadı.

## 1. Yedekler
Yedekleri (SQL + site ZIP) aldığınızı belirttiniz. Bu geliştirme ortamı **sizin hostinginizi ve yedek dosyalarınızı göremez**; yedeklerin varlığı ve içeriği buradan **doğrulanmadı** (durum: BEKLİYOR). Doğrulama sizin tarafınızda, salt okunur bir komutla yapılır:

```bash
php deploy/live/bin/live-backup-check.php /home/KULLANICI/yedek/rafimda.sql  /home/KULLANICI/yedek/site.zip
```
Araç: DB'ye bağlanmaz, dosyaya yazmaz/silmez/taşımaz, içerik basmaz. Boyut, tarih, SHA-256, `.sql(.gz)` içinde çekirdek tabloların (`users, products, orders, order_detail, categories, settings, modules`) varlığı ve INSERT sayısı, ZIP bütünlüğü ve `index.php/core/modules` varlığını raporlar. **"Kullanılabilir görünüyor" geri yükleme denemesi değildir.** Gerçek kanıt: SQL yedeğini **staging** veritabanına içe aktarıp `staging-preflight.php` ve site gezintisiyle bakmaktır (canlıya dokunmaz).

Yedekleri koruma kuralları: yedekleri `public_html` dışında tutun; bir kopyasını bilgisayarınızda saklayın; dosyayı sunucuda `chmod 400`; aynı adla yeni yedek almayın (tarih ekleyin); SHA-256 değerlerini not edin. ZIP içindeki `config/env.php` parola içerir: GitHub'a veya sohbete yüklemeyin.

## 2. Veritabanı etkisi (kod incelemesi)
- Rafımda modüllerinin migration/`install.sql` dosyaları **yalnızca yeni tablolar** oluşturur (59 tablo: `rafimda_*` 26 (12 + `rafimda_vendor_prefs` + 006: `rafimda_vendor_applications, rafimda_vendor_app_docs, rafimda_vendor_app_log, rafimda_notifications` + 007: `rafimda_store_profile, rafimda_follows, rafimda_question_meta, rafimda_store_questions, rafimda_review_meta, rafimda_review_votes, rafimda_store_reviews, rafimda_tickets, rafimda_ticket_messages`), `rd_*` 7, `xh_*` 12, `mh_*` 5, `rs_*` 2, `rc_*` 3, `rl_*` 4). `CREATE TABLE IF NOT EXISTS`; çekirdek tablolara (`users, products, orders, settings…`) **ALTER/DROP/TRUNCATE/DELETE/UPDATE yok**, yabancı anahtar yok. `ALTER TABLE … ADD COLUMN` yalnızca modüllerin kendi tablolarında.
- Modül **kurulumu** (panelden) çekirdek tablolarda **yeni satır** yazar: `modules` kaydı, `display_hooks` atamaları ve `settings` içine yeni anahtarlar (ör. `XML_HUB_SECRET`, `XML_HUB_CRON_KEY`, `MARKETPLACE_HUB_SECRET`, `RAFIMDA_VENDOR_AUTO_APPROVE`, `RAFIMDA_DEFAULT_COMMISSION`). Mevcut satırlar değiştirilmez.
- Çalışma zamanı: XML Hub içe aktarma ürün ekleyip güncelleyebilir (bu kullanıcı işlemidir, migration değildir). İlk canlı denemeler **tek küçük tedarikçi + önizleme (dry-run)** ile yapılmalıdır.
- Doğrulanmadı: gerçek MySQL'de çalışma, mevcut canlı şemayla (özellikle `products` sütunları) birebir uyum. Bu yüzden `SHOW COLUMNS FROM products;` ve `FS_VERSION` sonuçları gerekir.

## 3. Geri dönüş planı
| Seviye | Ne zaman | Nasıl | Veri etkisi |
|---|---|---|---|
| A. Modülü kapat | Yeni modül hata veriyorsa | Panel → Modüller → devre dışı | Veri korunur; site eski görünümüne döner |
| B. Dosya geri alma | Yeni dosyalar siteyi bozarsa | ZIP yedeğinden dosyaları geri yükleyin; yeni eklenen dizinleri (`modules/rafimda-*`, `modules/xml-hub`, `modules/marketplace-hub`, `deploy/`) `_to_delete_` altına taşıyın (silmeyin) | DB'ye dokunmaz |
| C. Eklenen tabloları bırak | Modül kaldırılacaksa | **Hiçbir şey yapmayın**: boş/ek tablolar çalışan siteyi etkilemez. Silme (`uninstall.destructive.sql.manual`) yalnızca sizin yazılı onayınızla | Veri kaybı yok |
| D. Veritabanı geri yükleme | Yalnızca veri bozulması olursa | Bakım modunda SQL yedeğinden geri yükleme (phpMyAdmin/Natro). **Yedekten sonra gelen siparişler/kayıtlar kaybolur**: bunun için siparişlerin durduğu bir pencere seçin | Yedek anına dönüş; son çare |
Karar sırası: A → B → (gerekirse) D. D'yi yalnızca siz karar verirsiniz.

## 4. Natro uyumluluğu (kod analizine göre; sunucuda doğrulanmadı)
- **PHP:** kurulum sihirbazı ≥ 7.4 ister; Rafımda kodu 8.1 için statik tarandı. Hedef 8.1+ (MultiPHP Manager). CLI ve web sürümleri farklı olabilir.
- **Eklentiler:** `pdo_mysql, mbstring, zip, curl, gd, intl, openssl, sodium, xmlreader/xmlwriter/dom` (XML Hub için).
- **İzinler:** `config/` yazılabilir (yalnızca kurulumda), `config/env.php` **600/640**, `cache/`, `logs/`, `img/products/`, `storage/` PHP kullanıcısı tarafından yazılabilir; `config/`, `core/`, `tools/`, `deploy/`, `logs/` web'den 403/404 vermeli.
- **Gizli bilgiler:** `config/env.php` ve ZIP yedekleri `public_html` içinde bırakılmaz; Git'te yok (`git ls-files` yalnızca `env.example.php`).
- **Oturum:** çekirdek `session.use_strict_mode`, HttpOnly, SameSite ve HTTPS'te Secure ayarlar; oturum dizini hosting varsayılanıdır. Doğrulanmadı: Natro'da oturum kayıt yolu yazılabilir mi (giriş yapınca "oturum kalıcı mı" testi).
- **Cron:** yalnızca panelde görünen anahtarlı uçlar (`xml-cron`, `pazaryeri-cron`) + çekirdeğin `api/cron`. Cron URL'sindeki anahtar sunucu loglarında görünür; anahtarı paylaşmayın, şüphede panelden yenileyin. Cron'u modül kurulumundan **sonra** ekleyin.
- **Yükleme yolları:** ürün görselleri `img/products`; XML görsel indirme ve satıcı yüklemeleri bu dizine yazar → disk kotası ve izin kontrolü.

## 5. Canlıya geçiş sırası (her adım ayrı onay)
1. Yedek doğrulama (§1) → siz çalıştırıp sonucu iletirsiniz.
2. SQL yedeğini **staging** DB'ye yükleyip `staging-preflight.php` (izolasyon) + gezinti.
3. Staging'de migration (`staging-migrate.php`) ve doğrulama; sonuçların raporu.
4. Canlı `live-preflight.php` (SELECT-only) + iki SQL.
5. Canlı `live-migrate.php` **dry-run** çıktısının incelenmesi.
6. **Yazılı onayınızla** `--apply` (yedek dosyası + `RAFIMDA_BACKUP_CONFIRM` + `RAFIMDA_MIGRATE_CONFIRM`).
7. **Ayrı yazılı onayla** panelden modül kurulumu (canlı CLI karşılığı henüz yok).
8. `live-verify.php`, ardından site gezintisi; sorun varsa §3.

## 6. Henüz eksik / riskler
- Canlı modül kurulumu için komut satırı karşılığı yok (§5/7 panelden).
- Gerçek MySQL, Natro ortamı, SSL, cron, gerçek tarayıcı ve mobil testleri yapılmadı (BEKLİYOR).
- Ana sayfa bağlantı denetimi, ekran görüntüleri ve bayi modülü belgeleri tamamlanmadı.
- Pazaryeri/ödeme/SMS entegrasyonları canlı servislerde denenmedi; Amazon/Pazarama/Ozon tamamlanmamış.

## 7. Sizden beklenen onaylar
| Onay | Durum |
|---|---|
| Yedek doğrulama çıktısının paylaşılması (`live-backup-check`) | bekleniyor |
| Staging DB'nin hazırlanması ve yedeğin oraya yüklenmesi | bekleniyor |
| Canlı `live-preflight` çalıştırılması | bekleniyor (önceki talimat) |
| `live-migrate --apply` | **verilmedi** |
| Panelden modül kurulumu | **verilmedi** |
| `tools/` ve `install/` taşıma/kaldırma | **verilmedi** |
