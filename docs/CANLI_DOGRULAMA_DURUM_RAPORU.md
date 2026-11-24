# Rafımda — Canlı ortam doğrulama durumu (96c7386 + bu rapor)

## Önce dürüst durum
Bu oturumda **gerçek sunucuya, gerçek MySQL/MariaDB'ye ve gerçek tarayıcıya erişim yoktur** (ortamda MySQL sunucusu da kurulu değil; yalnızca SQLite). Bu yüzden istenen "gerçek ortamda doğrulandı" durumuna **hiçbir özellik için ulaşılamadı**. Aşağıdaki tabloda "ÇALIŞIYOR" yoktur; bu bilinçli bir dürüstlüktür, eksiklik gizlemesi değildir. Kod düzeyinde (SQLite/sahte DB, gerçek `Settings`/`ModuleBase`/Smarty ile) 1021 test geçmiştir; bu "gerçek ortamda çalışır" anlamına gelmez. Bu turda yeni hata bulunmadı, çünkü yeni bir çalıştırma yapılamadı; kod değişmedi, **yeni paket gerekmedi** (en son paket: `rafimda-release-96c73864d7.zip`, SHA-256 `47de758ebedae13ae5477e6acca0e41af1ae52c719b317cc725f09ee0eba988b`).

Etiketler: ÇALIŞIYOR (gerçek ortamda doğrulandı) · HATALI · BEKLİYOR (gerçek ortam testi yapılamadı) · EKSİK (geliştirilmedi)

## 1. Canlı ortam uyumluluğu
| Madde | Durum | Not / nasıl doğrulanır |
|---|---|---|
| Gerçek PHP sürümü | BEKLİYOR | Natro'da bilinmiyor. `php deploy/live/bin/live-preflight.php` (salt okunur) çıktısı veya cPanel "PHP Sürümü". Gereksinim PHP ≥ 7.4; kod 8.3'te lint'li, 7.4'te çalıştırılmadı |
| FriSay sürümü (`FS_VERSION`) | BEKLİYOR | Depo temeli V3.0.2; canlıdaki değer panelde/`settings` tablosunda. Bize iletilmedi |
| MySQL/MariaDB uyumu | BEKLİYOR | SQL'ler MySQL biçimli, SQLite çevirisiyle sınandı. `live-preflight` sürüm, `sql_mode`, karakter kümesini okur |
| Eksik tablo/sütun tespiti | BEKLİYOR | `live-preflight` / `live-verify` (salt okunur) çıktısı gerekir; `products.desi/cargo_day/old_price` varlığı özellikle |
| Çekirdek sınıf uyumu | BEKLİYOR | Kod okunarak: `Module`, `ModuleBase`, `Settings`, `Product::save`, `Customer::login` imzaları uyuşuyor. Gerçek çekirdek çalıştırılmadı |

## 2. Modül kurulumu (kur / etkinleştir / devre dışı / yeniden etkinleştir)
Kurulum = `Module::install()`; "kuruldu ve etkinleştirildi" tek adımdır. Devre dışı/yeniden etkinleştirme panelin standart işlemidir; modüller devre dışıyken veri silmez (`uninstall()` veri silmez).

| Modül | Durum | Kanıt düzeyi |
|---|---|---|
| rafimda-theme | BEKLİYOR | Tablo yok. 32+14 test (gerçek Settings/ModuleBase/Smarty) |
| rafimda-vendor | BEKLİYOR | 24 sıralı kurulum testi + migration testleri (SQLite) |
| rafimda-showcase | BEKLİYOR | Aynı |
| xml-hub | BEKLİYOR | Aynı |
| marketplace-hub | BEKLİYOR | Aynı |

Devre dışı bırak → yeniden etkinleştir döngüsünü sunucuda siz uygulayın; beklenen: veriler korunur, rotalar kapanıp açılır.

## 3. Satıcı paneli
| İşlem | Durum |
|---|---|
| Kayıt ve giriş (`Customer::login` ortak oturum) | BEKLİYOR |
| Yetkilendirme (rol/izin) | BEKLİYOR |
| Ürün ekleme/düzenleme (`Product::save`) | BEKLİYOR |
| Stok/fiyat güncelleme | BEKLİYOR |
| Sipariş görüntüleme | BEKLİYOR |
| Satıcılar arası izolasyon | BEKLİYOR (kod/test düzeyinde sahiplik kontrolleri var; gerçek oturumla denenmedi) |

## 4. XML sistemi
URL bağlantısı, dosya içe aktarma, alan eşleştirme, varyant/kategori eşleştirme, stok/fiyat güncelleme, tekrar ürün kontrolü, hata kayıtları: **BEKLİYOR** (mantık testleri SQLite'ta geçiyor; gerçek tedarikçi XML'i ve MySQL ile denenmedi). Cron görevleri: **BEKLİYOR** (cPanel'de tanımlanmadıkça çalışmaz; satır `docs/CANLI_KURULUM_NATRO.md` §5'te).

## 5. Pazaryeri
| Pazaryeri | Durum |
|---|---|
| Trendyol, Hepsiburada, N11 | BEKLİYOR — bağdaştırıcı ve çekirdek istemci sarmalayıcısı kodu var; **gerçek API anahtarıyla denenmedi** (anahtarları sizden istemiyorum; panele siz girersiniz). HB/N11 "bağlantı testi" 404 çıkarımı canlı hesapla doğrulanmalı |
| Amazon, Pazarama, Ozon | EKSİK — bağdaştırıcı iskeleti var, gerçek entegrasyon tamamlanmadı |
| Pazaryeri siparişinden Rafımda siparişi/ürünü oluşturma | EKSİK |

## 6. Tema ve mobil
Ana sayfa, slider, kategori alanları, ürün kartları, mobil menü, satıcı giriş/kayıt görünümü, yönetici tema ayarları: **BEKLİYOR** (şablon derleme ve kaçışlama gerçek Smarty ile test edildi; gerçek theme4 sayfası ve tarayıcı görünümü görülmedi). Menü grubu hatası önceki turda bulunup düzeltildi.

## 7. Diğer
Bayi/alt bayi, gerçek ödeme/banka aktarımı, muhasebe: **EKSİK**.

## Canlıya geçiş adımları (sırayla, sizin yapacağınız)
1. Natro cPanel: tam yedek (dosyalar + veritabanı dökümü), sunucu dışına indirin. Geri yüklenebildiğini doğrulayın.
2. Önceki paketlerle ezilmiş olabilecek dosyaları kontrol edin (logo, favicon, kategori görselleri, tema renkleri, `.htaccess`, `robots.txt`).
3. `rafimda-release-96c73864d7.zip` → `public_html` → Extract (SHA-256 doğrulayın).
4. Salt okunur kontrol: `RAFIMDA_ENV=live RAFIMDA_LIVE_CONFIRM=EVET-CANLI-DB RAFIMDA_LIVE_DB_NAME=<ad> php deploy/live/bin/live-preflight.php` çıktısını (parola içermez) bana gönderin; PHP/MySQL/eksik sütun sorularını kapatırım.
5. Panelde sırayla: rafimda-theme → rafimda-vendor → rafimda-showcase → xml-hub → marketplace-hub. Her kurulumdan sonra ana sayfa ve `/admin` açılıyor mu bakın.
6. Test satıcısı oluşturun (gerçek müşteri olmadan), şu kabul listesini uygulayıp sonucu bana yazın:
   - `/satici-kayit` ile kayıt → `/satici-giris` ile giriş → `/satici-panel` açılıyor mu
   - Satıcı A ile ürün ekle/düzenle; stok ve fiyat değiştir
   - Satıcı B ile giriş yap: A'nın ürününü/siparişini görmemeli, adresini elle yazınca da erişememeli
   - Normal bir müşteri hesabıyla `/satici-panel` açılmamalı
   - XML Hub'da küçük bir test XML'i ile içe aktarma; aynı XML'i ikinci kez çalıştır (çift ürün oluşmamalı); hata kaydı sayfasını aç
   - Telefonda ana sayfa, mobil menü, satıcı giriş/kayıt sayfaları
7. cron'ları ancak adım 6 sonrası ekleyin.

## Riskler
- Panelden kurulum canlı DB'ye tablo yazar (silme yok). Yedek şart.
- `order.placed` kancası modül etkinleşince sipariş akışına dinleyici ekler (hatalar yutulur; yine de ilk gerçek siparişi izleyin).
- `rafimda-theme` etkinken `home_slider`'daki mevcut slider ile art arda görünür; tema renk ayarlarını ezer.
- Çekirdek tablolarda eski FriSay şeması farkı olabilir (`live-preflight` ile kontrol).
- HSTS ayarı apex alan adındaki tüm alt alan adlarını etkiler (`CANLI_KURULUM_NATRO.md` §1).

## Geri dönüş
- Görünüm sorunu: Modüller → `rafimda-theme` devre dışı (veri/tablo etkilenmez).
- Modül sorunu: ilgili modülü devre dışı bırakın; veriler kalır. Tabloları silmek yalnızca elle, `uninstall.destructive.sql.manual` ile ve yedek sonrası.
- Dosya geri dönüşü: adım 1'deki cPanel yedeğinden dosyaları geri yükleyin.
- Tam geri dönüş: yedeğin veritabanı dökümü (yalnızca onayınızla, sizin yapacağınız işlem).
