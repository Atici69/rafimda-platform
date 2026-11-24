# rafimda-vendor (v0.2.0)

Rafımda çok satıcılı altyapının ilk aşaması. Çekirdek dosyalara (`core/`, `container/`, `config/`, `templates/theme4`) **dokunmaz**.

## Kurulum (önce test/staging ortamında)
1. Veritabanı yedeği alın.
2. Yönetim → Modüller → "Rafımda Satıcı Sistemi" → Kur. Kurulum `install.sql` + `migrations/*.sql` çalıştırır (yalnızca `CREATE TABLE IF NOT EXISTS`; mevcut tablolara dokunmaz).
3. Yönetim → Satıcılar (Rafımda): başvuruları onaylayın. Varsayılan: otomatik onay KAPALI.
4. Adresler: `/satici-kayit`, `/satici-giris`, `/satici-panel`, `/satici-cikis`.

## Tablolar
`rafimda_vendors`, `rafimda_vendor_staff`, `rafimda_vendor_products` (+ `moderation*` sütunları, migration 002), `rafimda_audit_log`, `rafimda_migrations`, `rafimda_import_log` (002), `rafimda_vendor_orders`, `rafimda_vendor_order_items`, `rafimda_ledger`, `rafimda_payouts` (003).

Mevcut kurulumda yeni migration'lar Yönetim → Satıcılar ekranındaki "Migration çalıştır" uyarısıyla uygulanır (yalnızca eklemeli; 002, mevcut ürünleri `approved` olarak işaretler, davranışları değişmez).

## Ayarlar (Settings anahtarları)
`RAFIMDA_DEFAULT_COMMISSION` (varsayılan 10), `RAFIMDA_PAYOUT_HOLD_DAYS` (7), `RAFIMDA_PRODUCT_MODERATION` (boş/1 = yeni ürünler onaya düşer, 0 = kapalı), `RAFIMDA_VENDOR_AUTO_APPROVE`. Hepsi yönetim → Satıcılar → Ayarlar sekmesinden.

## Sipariş ve finans
- `order.placed` hook'u siparişi satıcılara böler; `order.updated` teslimde bakiyeyi serbest bırakma sürecini başlatır, iptal/iadede ters kayıt yazar. Hook hataları sipariş akışını engellemez.
- Defter (`rafimda_ledger`) yalnızca ekleme yapar, `ref_key` UNIQUE ile idempotenttir. Silme/güncelleme metodu yoktur (DB tetikleyicisi YOKTUR; yalnızca kod düzeyinde garanti).
- Satıcı yalnızca `preparing`/`shipped` durumlarını verebilir; teslim/iptal/iade çekirdek sipariş durumundan türetilir.

## Henüz YAPILMADI
XML Hub, pazaryeri adaptörleri, Excel içe aktarma, abonelik/paketler, iptal/iade talebi akışı, raporlar/grafikler, çoklu mağaza arayüzü, yöneticide kategori/kampanya yönetimi, DB düzeyinde defter koruması, MySQL'de uçtan uca ve görsel mobil test.

## Geri dönüş
- Modülü devre dışı bırakmak rotaları kapatır; veri silinmez (`uninstall.sql` zararsızdır).
- Verileri bilerek silmek: yedek aldıktan sonra `uninstall.destructive.sql.manual` içeriğini elle çalıştırın. `products` tablosuna dokunmaz.

## Mimari kurallar
- Kimlik = FriSay `users` (Customer sınıfı). Satıcı için ayrı parola/hesap yoktur.
- Yetki = `rafimda_vendor_staff.role` → `RvPerm`. Her istekte `RvContext::can()` ile sunucu tarafında denetlenir.
- Ürün sahipliği = `rafimda_vendor_products`. Ürün ID'si alan her yazma işlemi `RvProducts` içinde önce `owns()` ile doğrulanır; `Product::save/deleteById` bu sınıf dışından satıcı girdisiyle çağrılmamalıdır.

## Testler
```
php modules/rafimda-vendor/tests/run.php          # SQLite + gerçek Smarty; Product/Customer TAKLİT
php modules/rafimda-vendor/tests/load_module.php  # gerçek ModuleBase ile yükleme kuralları
```
Bu testler MySQL ve gerçek `Product::save`/`Customer::login` çalıştırmaz; canlıya benzer bir MySQL ortamında ayrıca uçtan uca denenmelidir.
