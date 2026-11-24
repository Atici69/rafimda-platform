# rafimda-vendor (v0.1.0)

Rafımda çok satıcılı altyapının ilk aşaması. Çekirdek dosyalara (`core/`, `container/`, `config/`, `templates/theme4`) **dokunmaz**.

## Kurulum (önce test/staging ortamında)
1. Veritabanı yedeği alın.
2. Yönetim → Modüller → "Rafımda Satıcı Sistemi" → Kur. Kurulum `install.sql` + `migrations/*.sql` çalıştırır (yalnızca `CREATE TABLE IF NOT EXISTS`; mevcut tablolara dokunmaz).
3. Yönetim → Satıcılar (Rafımda): başvuruları onaylayın. Varsayılan: otomatik onay KAPALI.
4. Adresler: `/satici-kayit`, `/satici-giris`, `/satici-panel`, `/satici-cikis`.

## Tablolar
`rafimda_vendors`, `rafimda_vendor_staff`, `rafimda_vendor_products`, `rafimda_audit_log`, `rafimda_migrations`.

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
