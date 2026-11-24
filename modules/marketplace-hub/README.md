# Pazaryeri Hub (v0.1.0)

Trendyol, Hepsiburada, N11, Amazon, Pazarama ve Ozon için **bağımsız bağdaştırıcı mimarisi**.

## ⚠ Gerçek durum
**Hiçbir pazaryeri için gerçek API entegrasyonu YOKTUR.** Bağdaştırıcılar yalnızca kimlik alanlarını tanımlar (`implemented() = false`).
Bağlantı testi başarısız döner, ürün gönderimi yapılmaz; kayıtlar `blocked` olarak, nedeniyle birlikte görünür. Sahte başarı üretilmez.
Çalışan kısımlar: şifreli kimlik saklama, kategori eşleme, ürün sahiplik denetimi, payload kurma, gönderim kuyruğu / yeniden deneme / hata günlüğü (test bağdaştırıcısıyla doğrulandı).

## Kurulum
1. Yönetim → Modüller → Pazaryeri Hub → Kur (`install.sql` + `migrations/*.sql`; yalnızca `CREATE TABLE IF NOT EXISTS`).
2. Kuyruk için cron: `/pazaryeri-cron?q=cron&key=ANAHTAR` (adres yönetim ekranında). Şu an tüm bağdaştırıcılar uygulanmadığından kuyruk gönderim yapmaz.
3. Satıcı sayfası: `/pazaryeri` (izinler: `marketplaces.view`, `marketplaces.manage`; yönetici/owner ve manager rollerinde).

## Bağdaştırıcı eklemek
`adapters/MhAdapterXxx.php` içinde `MhAbstractAdapter`'ı genişletin; `implemented()` yalnızca gerçek API ile uçtan uca test edildikten sonra `true` olmalıdır. `credentialFields()`, `testConnection()`, `submit()` uygulanır; `MhRegistry::BUILTIN`'e eklenir. `submit()` `['success','remote_id','error','retryable']` döner.

## Güvenlik
- Kimlik bilgileri libsodium ile şifrelenir (`MARKETPLACE_HUB_SECRET` Settings anahtarı; yedeğini alın), arayüze/günlüğe/liste çıktısına verilmez, yalnızca gönderim anında çözülür.
- Her hesap/gönderim/hata `id_vendor` ile kapsamlıdır; ürünler hem kuyruğa alırken hem gönderirken `MhOwner::owns()` ile doğrulanır.

## Tablolar
mh_migrations, mh_accounts, mh_category_map, mh_listings, mh_errors. Kaldırma veri silmez; `uninstall.destructive.sql.manual` elle çalıştırılır.

## Veri kaynağı
`MhPayload` ortak ürün tablosunu okur; XML Hub kuruluysa kargo ücreti ve kaynak/tedarikçi bilgisi (`xh_product_meta`) payload'a eklenir.

## Testler
`php modules/marketplace-hub/tests/run.php` (93 test, SQLite + stub) ve `tests/load_module.php`. MySQL ve gerçek pazaryeri API'leri test EDİLMEDİ.

## Eksikler
- Hiçbir pazaryerinde gerçek API, uzak kategori listesi çekme, stok/fiyat/sipariş geri senkronizasyonu yok.
- Gönderim yalnızca ürün oluşturma/güncelleme isteği modelidir; pazaryerinden dönen onay durumu sorgulaması yok.
- Arayüz tarayıcıda denenmedi.
