# XML Hub (v0.2.0)

Rafımda için bağımsız XML tedarikçi/ürün besleme modülü. Satıcılar (rafimda-vendor varsa) yalnızca kendi bağlantı ve ürünlerini yönetir; platform yöneticisi `id_vendor=0` bağlantılarla platform ürünlerini besler.

## Kurulum
1. Yönetim → Modüller → XML Hub → Kur. `install.sql` + `migrations/*.sql` çalışır (yalnızca `CREATE TABLE IF NOT EXISTS`, yıkıcı komut yok).
2. Yönetim → XML Hub ekranında gösterilen **Cron adresini** (`/xml-cron?q=cron&key=...`) sunucu cron'una ekleyin (dakikada bir):
   `* * * * * curl -s "https://alanadiniz/xml-cron?q=cron&key=ANAHTAR" >/dev/null`
   `q=cron` parametresi önerilir/gereklidir: çekirdek sayfa önbelleği `q`/`query` içeren istekleri önbelleğe almaz. (Ölçüm: bu çekirdek sürümünde `Performance::canUsePageCache()` `Tools::getValue()` varsayılanı `false` olduğu için `false !== ''` nedeniyle şu an HER sayfa için `false` döner, yani sayfa önbelleği fiilen çalışmıyor; çekirdek düzeltilirse `q=cron` cron'u korur. Çekirdeğe dokunulmadı.)
3. Kuyruk, panelden "Kuyruğu şimdi çalıştır" ile de elle işletilebilir.

## Güvenlik
- SSRF: yalnızca http/https, portlar 80/443/8080/8443, kullanıcı bilgisi yok, sayısal/hex/oktal host yok, yerel host adları yok; DNS çözümünde TÜM IP'ler genel olmalı; IP, CURLOPT_RESOLVE ile sabitlenir; en çok 3 yönlendirme, her adım yeniden doğrulanır.
- Boyut sınırı akış sırasında uygulanır (XML 50MB, görsel 5MB), zaman aşımları var.
- XML: DOCTYPE/ENTITY reddedilir, UTF-16/32 reddedilir, LIBXML_NONET, derinlik ≤40, kayıt başına ≤3000 düğüm, ≤200k kayıt, XMLReader ile akış.
- Satıcı izolasyonu sunucu tarafında (`XhRepo` kapsamı + `xml.view`/`xml.manage` izinleri + CSRF).

## Tablolar
xh_migrations, xh_suppliers, xh_connections, xh_items, xh_runs, xh_errors, xh_price_history, xh_stock_history (001); xh_product_meta (ürün kaynağı/tedarikçi, kargo ücreti, içerik parmak izi), xh_credentials (şifreli Basic Auth), xh_changes (açıklama/görsel/kargo/varyant değişiklik kaydı) (002).
Kaldırma güvenlidir (veri silmez); tabloları silmek için `uninstall.destructive.sql.manual` elle çalıştırılmalıdır.

## Testler
`php modules/xml-hub/tests/run.php`, `tests/run_v2.php` ve `tests/load_module.php` (SQLite + stub; MySQL, gerçek curl/DNS ve gerçek Product::save TEST EDİLMEDİ).

## v0.2.0 ile gelenler
- Basic Auth (yalnızca HTTPS; libsodium ile şifreli; başka sunucuya yönlendirmede gönderilmez; arayüzde/günlükte gösterilmez). Anahtar `XML_HUB_SECRET` (Settings) — yedeğini alın: kaybolursa kayıtlı kimlik bilgileri çözülemez.
- Ürün kaynağı/tedarikçi izleme (`XhMeta::source()`), kargo ücreti (`xh_product_meta.cargo_price`; çekirdek `products` tablosunda alan olmadığı için modül tablosunda), açıklama değişikliği algılama (parmak izi), görsel modları (`if_empty`, `add_new`), mevcut ürüne yeni varyant ekleme, tek ürünlü varyantlı besleme algılama, marka/kategori eşleştirme ekranı.

## Bilinen sınırlar
- Pazaryeri bağdaştırıcıları için bkz. Aşama B (varsa `modules/marketplace-hub`).
- Zamanlama harici cron'a bağlıdır.
- Görseller silinmez/değiştirilmez (yalnızca eklenir). Kargo ücreti ürün sayfasına/ödemeye yansımaz; yalnızca `xh_product_meta` içinde tutulur ve pazaryeri gönderimlerinde kullanılabilir.
- Marka/kategori otomatik oluşturulmaz (eşleştirilir).
- Mevcut varyant yalnızca SKU ile bulunur; SKU'suz varyant atlanır.
- Her tick offset'e kadar yeniden akış okur (O(n)).
- Mobil/görsel arayüz tarayıcıda denenmedi; MySQL, gerçek curl/DNS/TLS ve gerçek `Product::save` ile uçtan uca test edilmedi.
