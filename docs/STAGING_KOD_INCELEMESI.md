# Staging Paketi Kod İncelemesi (deploy/staging)

Kapsam: `deploy/staging/` altındaki tüm dosyalar (lib 6, bin 5, conf 6, tests 4) + `docs/STAGING_KURULUM.md`. Yöntem: dosya dosya okuma, FriSay çekirdeği/modül kurulum akışıyla karşılaştırma, hata enjeksiyonlu testler. **MySQL'de hiçbir şey çalıştırılmadı.**

## 1–2. SQL ayrıştırıcı ve migration davranışı
| Bulgu | Önem | Durum |
|---|---|---|
| Eski ayırıcı tırnak içi `;` ve `-- ` ile `#`/`/* */` yorumlarını doğru işlemiyordu; kapanmamış tırnakta sessizce yanlış bölebilirdi | Orta | **Düzeltildi:** tırnak/yorum farkındalıklı yeni ayırıcı; kapanmamış tırnak/blok yorumu, `DELIMITER`, `CREATE PROCEDURE/TRIGGER/EVENT` istisna fırlatır (sessiz hata yok). 14 yeni test |
| Modüllerin kendi `runSqlFile`/`*Migrator` kodları `--` ile başlayan ifadeyi tamamen atar (yorumdan hemen sonra gelen ifade kaybolur) | Düşük (şu an hiçbir dosyada yorum yok) | Çekirdeğe/modüllere dokunulmadı; bizim araç bunu yapmaz. Test, yorumdan sonraki ifadenin kaybolmadığını doğrular |
| Transaction | Bilgi | MySQL'de DDL örtük commit yapar; dosyalar transaction'a sarılmaz. Yarım kalan dosya sonrası yeniden çalıştırma güvenlidir: tüm gerçek dosyalar `CREATE … IF NOT EXISTS` + korumalı `ADD COLUMN`; **testle doğrulanan değişmez: hiçbir install/migration dosyasında INSERT/UPDATE/DELETE yok** |
| `ALTER … ADD COLUMN` idempotansı | — | Sütun varsa atlanır (information_schema); yarım kalmış 004 senaryosu testli |
| `AUTO_INCREMENT` | Orta | Ayrıştırıcı AUTO_INCREMENT sütunlarını çıkarır, **doğrulayıcı MySQL'de `EXTRA` alanıyla denetler** (eksikse FAIL). SQLite çevirisinde `INTEGER PRIMARY KEY AUTOINCREMENT` kullanıldığından gerçek MySQL davranışı **kanıtlanmadı** |

## 3. Migration sırası – gerçek FriSay akışıyla uyum
Modüllerin `install()` metotları (rafimda-vendor, xml-hub, marketplace-hub, rafimda-showcase) **install.sql → kendi migrator'ı** (showcase: install.sql → `RsShowcase::ensureDefaults`) sırasını izler; aynı sıra ve aynı izleme tabloları (`rafimda_migrations`, `xh_migrations`, `mh_migrations`) kullanılır. Modüller arasında FOREIGN KEY/REFERENCES yok → modül sırası bağımsız; rafimda-vendor önce (paket kotaları öteki hub'lara bakar). Panelden sonradan kurulum bekleyen migration bulmaz. **Fark:** `ensureDefaults`/ayar tohumlama gibi PHP adımları yalnızca panel kurulumunda çalışır; bu yüzden panelden etkinleştirme zorunludur (dokümanda §6).

## 4. Seed tekrar çalıştırılabilirlik ve koruma
- Yalnızca ekler; işaretçilerle idempotent (kayıtlar çoğalmaz — testli, iki çalıştırma).
- **Yeni test:** önceden var olan TEST olmayan kullanıcı/kategori/marka/ürün/sipariş/kalem/satıcı kayıtları seed (iki çalıştırma) sonrası **birebir aynı**; gerçek siparişe satıcı siparişi/komisyon yazılmaz.
- **Düzeltildi:** `--allow-existing-orders` bypass bayrağı kaldırıldı; TEST olmayan sipariş varsa araç çıkar (kural testle denetlenir).
- Tek platform yazması: `free` paket yoksa eklenir, `test-sinirli` paketi eklenir (belgelendi).
- Doğrulanmadı: gerçek `config/settings.php` açılışının CLI'de çalışması.

## 5. Canlı DB korumasının komutlar arası tutarlılığı
4 komutun hepsi `staging_guard_or_exit()` ile başlar; bağlantı/açılıştan önce çağrıldığı, bypass bayrağı bulunmadığı testle denetlenir. Seed ve `--init-lazy` ayrıca `config/env.php` ↔ ortam DB eşleşmesini ve açılış sonrası `_DB_NAME_` değerini doğrular. **Bilinen sınır:** koruma, `RAFIMDA_PROD_DB_NAME` değerinin doğru girilmesine ve DB adının staging/test içermesine dayanır; yanlış girilmiş ve "test" içeren bir canlı DB adı korumayı aşar (insan hatası, kod ile çözülemez).

## 6. staging_guard.php, robots, nginx, Apache
| Bulgu | Önem | Durum |
|---|---|---|
| `staging_guard.php` env.php yokken (kurulum sihirbazı) isteği 503'le kilitliyordu | Yüksek (kurulumu engeller) | **Düzeltildi:** env.php yoksa izin; karar `StagingGuard::requestVerdict` ile testli |
| `deploy/` dizini web'den erişilebilir (FriSay engel listesinde yok): `tests/run.php` web'den çalışabilirdi | Yüksek | **Düzeltildi:** tüm PHP dosyaları CLI dışında 404; nginx `location ^~ /deploy/ { deny all; }`, Apache `^deploy(/|$)` engeli (robots.staging.txt hariç); canlıda da eklenmeli |
| nginx `add_header` miras kuralı (kendi `add_header`'ı olan location üst seviyeyi miras almaz) | Orta | Uyarı eklendi; `staging_guard.php` PHP yanıtlarında başlığı her durumda ekler |
| Apache robots için kamuya açık dizin yönlendirmesi | Orta | Dahili rewrite + deploy engeli ile birleştirildi |
| nginx cron muafiyeti `try_files` yanlış olabilirdi | Düşük | `location /` ile aynı satırın kullanılması belirtildi; gerçek yönlendirme **doğrulanmadı** |
| `sendmail_path` | Orta | Havuz ayarı önerisi eklendi |

## 7. APP_ENV=production ile staging önlemleri
Doğrulananlar (kod okuma): production'da hata gizlenir (`App::configureErrors`), `Secure` çerez ve HSTS yalnızca production+HTTPS'te (`App::sendSecurityHeaders`, `SecuritySettings::hstsEnabled`). **Bulgu:** HSTS `includeSubDomains` ile 1 yıl — yalnızca staging alanını etkiler (üst alanı değil); dokümana ve `SECURITY_HSTS=off` önerisine yazıldı. **Açık risk:** `MAIL_DRIVER=php` gerçek e-posta atabilir → `sendmail_path=/bin/true`, doğrulayıcıya SMTP/SMS/ödeme/API anahtarı uyarısı (testli). Basic Auth + noindex ayarları hazır; **gerçek sunucuda uygulanmadı**.

## 8. Gizli anahtarlar ve yedekler
- `XML_HUB_SECRET`, `MARKETPLACE_HUB_SECRET`, `XML_HUB_CRON_KEY` `settings` tablosundadır → **DB dökümü anahtarı da taşır**; şifreleme yalnızca DB'nin tek başına sızmasına karşı kısmen koruma sağlar. Canlıda anahtarı DB yedeğinden ayrı saklayın.
- **Düzeltildi:** `.gitignore` yedek/döküm/anahtar dosyalarını (`backups/*`, `*.sql.gz`, `*.dump`, `*.key`, `*.sqlite`, `staging.env`) dışlamıyordu; eklendi (testli). Repoda gizli bilgi taraması testi: temiz.
- `env.php` izin kontrolü (diğer kullanıcılar okuyamamalı) doğrulayıcıya eklendi.

## 9. product_variations
Tablo çekirdek tarafından `ProductVariation::ensureSchema()` ile **ilk kullanımda** (MySQL `SHOW TABLES` ile) oluşturulur; kurulumda yoktur. Çözüm: `staging-verify.php --init-lazy` (ve seed) çekirdeği açıp `ensureSchema()` çağırır; bayrakla tablo yoksa FAIL, bayraksız yalnızca uyarı. Kalan risk: tablo yokken `RvInventory` her satır için `error_log` yazar (işlevsel hata değil). `ensureSchema()` MySQL'e özgüdür, **gerçek MySQL'de denenmedi**.

## 10. Test sonuçları ve kapsam
| Takım | Test |
|---|---|
| rafimda-vendor run / run_v2 | 159 / 89 |
| xml-hub run / v2 / v3 | 217 / 85 / 43 |
| marketplace-hub run / v2 | 105 / 55 |
| rafimda-showcase | 59 |
| **Önceki toplam** | **812** |
| staging/run | 105 |
| staging/run_seed | 16 |
| **Genel toplam** | **933, 0 başarısız** (`php deploy/staging/tests/run_all.php`) |

(Önceki rapordaki 72, 60+12 idi; inceleme sonrası 121.) Kapsanan: koruma kuralları, SQL ayrıştırma, migrator durma/yeniden başlama, yıkıcı ifade reddi, şema doğrulayıcı (eksik tablo/sütun/indeks/AUTO_INCREMENT/migration), komut tutarlılığı, web erişim engeli, gizli bilgi/hijyen, seed (gerçek çekirdek şemasında). **Kapsanmayan:** MySQL, gerçek `config/settings.php` açılışı, gerçek Product/Customer/Security/oturum/CSRF, nginx/Apache yapılandırmasının gerçek sunucuda çalışması, cron, tarayıcı.

## Karar
**Staging kurulum DENEMESİNE hazır; kurulumu DOĞRULANMIŞ değil.** Kod, güvenlik kuralları ve araçlar tamamdır ve mantık düzeyinde testlidir; ilk gerçek çalıştırmada MySQL'e özgü sürprizler çıkabilir (bkz. doküman §14). Başarı ölçütü: `staging-migrate` 0 FAIL, `staging-verify --init-lazy` 0 FAIL, `staging-seed` 0 FAIL.
