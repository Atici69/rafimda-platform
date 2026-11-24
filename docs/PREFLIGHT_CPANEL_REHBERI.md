# live-preflight — cPanel'de adım adım (commit b73cccb / paket 96c7386)

Amaç: **hiçbir şeyi değiştirmeden** canlı ortamı ölçmek. Bu adımlar bitip çıktı incelenmeden hiçbir migration/modül kurulumu yapılmaz.

## 0. Bu komut ne yapar / ne yapmaz (kodla doğrulandı)
**Yaptığı (hepsi okuma):** PHP sürümü ve eklentiler; çekirdek dosyaların varlığı; `cache/ logs/ tmp/` yazılabilir mi; `config/env.php` izni; `SELECT VERSION()`, `@@sql_mode`, `@@character_set_database`; `SELECT COUNT(*)` (yalnızca settings, products, users, orders, admins, categories, rafimda_vendors tabloları için **satır sayısı**); modül migration takip tablolarından `SELECT version` (bekleyen migration listesi).
**Yapmadığı:** INSERT/UPDATE/DELETE/ALTER/CREATE/DROP/TRUNCATE yok; dosya yazmaz/silmez; ağ isteği yapmaz; kayıt içeriği (müşteri, sipariş, ürün) okumaz. Araçta yazan SQL ifadelerinin tamamı yukarıdaki SELECT'lerdir (kaynak incelemesi + `deploy/live/tests` içindeki "yıkıcı SQL yok" testi).
**Sınır:** "Yazmaz" garantisi kod düzeyindedir. Veritabanı düzeyinde garanti, yalnızca SELECT yetkili ayrı bir MySQL kullanıcısı gerektirir; araç env.php'deki mevcut kullanıcıyı kullanır.
**Yapmadığı kontrol (önceki raporda yanlış yazılmıştı):** eksik tablo/sütun taraması ve `products.desi/cargo_day/old_price` kontrolü preflight'ta **yoktur** — bkz. §5.

## 1. Ön koşullar
- Yedek alınmış olmalı (preflight yazmaz ama sıradaki adımlar yazar).
- Paket `public_html` içine açılmış olmalı (`public_html/deploy/live/bin/live-preflight.php` var mı bakın).
- `config/env.php` içinde `APP_ENV` = `production` ve `APP_DEBUG` kapalı olmalı. **Değilse araç veritabanına hiç bağlanmadan REDDEDİLDİ der.** env.php'yi değiştirmeden önce bana sorun/nedenini yazın (HSTS ve hata gizleme davranışını değiştirir).
- `DB_NAME` değerini bilmeniz gerekir (cPanel → MySQL Veritabanları'nda adı görünür; parola gerekmez).

## 2A. cPanel Terminal varsa (en kolay)
```bash
cd ~/public_html
export RAFIMDA_ENV=live RAFIMDA_LIVE_CONFIRM=EVET-CANLI-DB RAFIMDA_LIVE_DB_NAME=BURAYA_DB_ADI
php deploy/live/bin/live-preflight.php > ~/preflight-cikti.txt 2>&1
cat ~/preflight-cikti.txt
```
## 2B. Terminal yoksa: tek seferlik Cron
cPanel → Cron Jobs → "Bir kerelik" için dakika seçin (şu andan 2–3 dk sonrası) ve komut olarak (tek satır; `KULLANICI` ve `DB_ADI` sizin):
```
RAFIMDA_ENV=live RAFIMDA_LIVE_CONFIRM=EVET-CANLI-DB RAFIMDA_LIVE_DB_NAME=DB_ADI /usr/local/bin/php /home/KULLANICI/public_html/deploy/live/bin/live-preflight.php > /home/KULLANICI/preflight-cikti.txt 2>&1
```
- Çıktıyı **`public_html` dışına** (ev dizini) yazın; içine yazarsanız internetten okunabilir olur.
- Çalıştıktan sonra cron satırını **silin**. Çıktıyı File Manager'dan açın.
- `php` yolu farklıysa (ör. `/opt/cpanel/ea-php81/root/usr/bin/php`) cPanel → MultiPHP Manager veya hosting desteğinden sürümünüzün CLI yolunu öğrenin. **CLI PHP sürümü web PHP sürümünden farklı olabilir**; çıktıdaki sürüm CLI'nındır, web sürümünü cPanel → MultiPHP Manager'dan ayrıca not edin.

## 3. Göndermeden önce çıktıyı kontrol edin (parola/anahtar)
Araç şunları **yazmaz**: DB parolası, `XML_HUB_SECRET`/cron anahtarları, API anahtarları (ayarlar tablosu içerik olarak okunmaz). Hata mesajlarında DB parolası `***` ile maskelenir. Yine de göndermeden önce gözle bakın; çıktıda **görebileceğiniz kişisel/özel ama gizli olmayan** bilgiler: veritabanı adı, sunucu dizin yolu (`/home/KULLANICI/...`), MySQL sürümü, kayıt sayıları. İsterseniz KULLANICI ve veritabanı adını `XXX` ile değiştirin. Parola, anahtar veya token gibi bir şey görürseniz **göndermeyin**, bana söyleyin.

## 4. Çıktıyı nasıl okuyacaksınız
Satır biçimi: `[OK]`, `[WARN]`, `[FAIL]`. Son satır: `ÖZET: N kontrol, X başarısız, Y uyarı.` Çıkış kodu: 0 = başarısız yok; 1 = en az bir FAIL; 2 = güvenlik kapısı reddetti; 3 = veritabanına bağlanılamadı.

| Satır | Beklenen | Anlamı / yapılacak |
|---|---|---|
| PHP sürüm >= 7.4 | OK | FAIL ise kurulum yapmayın; PHP'yi yükseltmek ayrı karar (benim onayımla) |
| eklenti pdo_mysql, mbstring, json, sodium, curl | OK | Zorunlu biri FAIL ise modüller çalışmaz; cPanel → PHP eklentileri |
| eklenti (diğerleri) | OK/WARN | WARN önerilir demektir (ör. zip, gd) |
| Çekirdek: dosya index.php, core/Module.php… | OK | FAIL ise paket eksik/yanlış yere açılmış |
| Çekirdek: modül dizini (5 adet) | OK | FAIL ise zip düzgün açılmamış |
| yazılabilir cache/ logs/ tmp/ | OK | FAIL ise klasör izni (755/775) düzeltilmeli |
| env.php izinleri | OK | FAIL: "diğer kullanıcılar" okuyabiliyor → izni 600/640 yapın (güvenlik) |
| Veritabanı: bağlantı + sürüm | OK, ör. `10.x-MariaDB / dbadi` | MySQL ≥5.7 / MariaDB ≥10.3 beklenir; eski sürümde söyleyin |
| sql_mode STRICT | OK veya WARN | WARN: katı mod açık; modüllerin INSERT'leri staging'de denenmedi, ilk kurulumda dikkat |
| karakter kümesi | utf8mb4 | utf8 (3 baytlık) görürseniz söyleyin |
| çekirdek tabloları kurulu | OK | FAIL: FriSay kurulu değil/farklı şema; **durun** |
| mevcut veri (yalnızca sayılar) | WARN + sayılar | Beklenen (canlı site dolu); **hiçbir şey sıfırlanmaz** |
| bekleyen migration | `{"rafimda-vendor":["001_init",…],…}` | İlk kurulumda hepsi bekleyen olur: normal. `[]` = zaten uygulanmış |

Karar: **FAIL yoksa** ve çıktıyı bana gönderdikten sonra ben "devam" demeden **hiçbir modül kurulmaz, migration çalıştırılmaz.**

## 5. Preflight'ın kapsamadığı salt okunur kontroller (phpMyAdmin → SQL)
Yalnızca şunları çalıştırın (hepsi SELECT/SHOW; başka sorgu yazmayın, `settings` tablosunu genel olarak listelemeyin — anahtarlar içerir):
```sql
SHOW COLUMNS FROM products;
SELECT value FROM settings WHERE title = 'FS_VERSION';
```
`products` çıktısında `desi`, `cargo_day`, `old_price`, `price`, `stock`, `active` sütunları görünmeli. Sonuçları (sütun adları + FS_VERSION değeri) bana yazın.

## 6. Hata olursa hangi dosya incelenir
| Belirti | İncelenecek |
|---|---|
| `REDDEDİLDİ` + madde listesi | `deploy/live/lib/LiveGuard.php::check` (hangi koşul) ve `config/env.php` (APP_ENV, APP_DEBUG, DB_NAME) |
| `Veritabanı bağlantısı kurulamadı` (kod 3) | `deploy/live/bin/_common.php::live_guard_and_connect`, env.php DB_HOST/DB_USER; cPanel'de kullanıcının veritabanına yetkisi |
| `command not found` / `Could not open input file` | php yolu veya `deploy/live/bin/` yolu; zip'in deploy/ klasörünü açıp açmadığı |
| PHP/eklenti FAIL | `deploy/staging/lib/StagingVerifier.php::checkPhp` (EXTENSIONS listesi) |
| Çekirdek dosya/modül dizini FAIL | `StagingVerifier::checkCoreFiles` (CORE_FILES); zip bütünlüğü |
| bekleyen migration beklenmedik | `deploy/staging/lib/StagingModules.php`, `StagingMigrator::applied` ve ilgili modülün `*_migrations` tablosu |
| Çıktı boş / PHP hata gösteriyor | CLI php sürümü; `deploy/live/bin/live-preflight.php` ilk satırları |

## 7. Mevcut sürümü koruma
Preflight hiçbir dosya/ayar/veri değiştirmez; çalıştırılması siteyi etkilemez. Çalıştırdıktan sonra: cron satırını ve `~/preflight-cikti.txt` dosyasını silin. Zip dosyasında değişiklik yok (96c7386).
