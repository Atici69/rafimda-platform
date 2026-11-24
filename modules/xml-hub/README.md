# XML Hub (v0.1.0)

Rafımda için bağımsız XML tedarikçi/ürün besleme modülü. Satıcılar (rafimda-vendor varsa) yalnızca kendi bağlantı ve ürünlerini yönetir; platform yöneticisi `id_vendor=0` bağlantılarla platform ürünlerini besler.

## Kurulum
1. Yönetim → Modüller → XML Hub → Kur. `install.sql` + `migrations/*.sql` çalışır (yalnızca `CREATE TABLE IF NOT EXISTS`, yıkıcı komut yok).
2. Yönetim → XML Hub ekranında gösterilen **Cron adresini** sunucu cron'una ekleyin (dakikada bir):
   `* * * * * curl -s "https://alanadiniz/xml-cron?q=cron&key=ANAHTAR" >/dev/null`
   `q=cron` parametresi ZORUNLUDUR: çekirdek sayfa önbelleği ziyaretçi GET isteklerini önbelleğe alır, `q` içeren istekleri almaz. Bu olmadan cron çıktısı önbellekten dönüp kuyruğu çalıştırmayabilir.
3. Kuyruk, panelden "Kuyruğu şimdi çalıştır" ile de elle işletilebilir.

## Güvenlik
- SSRF: yalnızca http/https, portlar 80/443/8080/8443, kullanıcı bilgisi yok, sayısal/hex/oktal host yok, yerel host adları yok; DNS çözümünde TÜM IP'ler genel olmalı; IP, CURLOPT_RESOLVE ile sabitlenir; en çok 3 yönlendirme, her adım yeniden doğrulanır.
- Boyut sınırı akış sırasında uygulanır (XML 50MB, görsel 5MB), zaman aşımları var.
- XML: DOCTYPE/ENTITY reddedilir, UTF-16/32 reddedilir, LIBXML_NONET, derinlik ≤40, kayıt başına ≤3000 düğüm, ≤200k kayıt, XMLReader ile akış.
- Satıcı izolasyonu sunucu tarafında (`XhRepo` kapsamı + `xml.view`/`xml.manage` izinleri + CSRF).

## Tablolar
xh_migrations, xh_suppliers, xh_connections, xh_items, xh_runs, xh_errors, xh_price_history, xh_stock_history.
Kaldırma güvenlidir (veri silmez); tabloları silmek için `uninstall.destructive.sql.manual` elle çalıştırılmalıdır.

## Testler
`php modules/xml-hub/tests/run.php` ve `php modules/xml-hub/tests/load_module.php` (SQLite + stub; MySQL, gerçek curl/DNS ve gerçek Product::save TEST EDİLMEDİ).

## Bilinen sınırlar
- Pazaryeri bağdaştırıcıları (Trendyol, Hepsiburada, N11, Amazon, Pazarama, Ozon) YOK; modül yalnızca `xh_items` + ürünler ile veri kaynağı sağlar.
- Zamanlama harici cron'a bağlıdır.
- Görsel eşitleme yalnızca ürünün görseli yoksa ekler; açıklama farkı denetlenmez; kargo ücreti ayrıştırılır ama ürüne yazılmaz.
- Marka/kategori yalnızca birebir ad eşleşmesiyle bulunur, otomatik oluşturulmaz.
- Mevcut ürüne yeni varyant eklenmez (yalnızca SKU ile eşleşen varyant güncellenir). Tek ürünlü + varyantlı beslemelerde kayıt yolu elle seçilmelidir.
- Basic-auth'lu beslemeler desteklenmez. Her tick offset'e kadar yeniden akış okur (O(n)).
- Mobil/görsel arayüz tarayıcıda denenmedi.
