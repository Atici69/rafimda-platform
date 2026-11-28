# Rafımda XML Merkezi — Baştan Geliştirme

Durum: geliştirme taslağı. Bu modül canlı ortamda kurulmuş veya uçtan uca doğrulanmış değildir.

## Amaç
Frisay 3.1 ürün ve varyant kayıt katmanını kullanarak tedarikçi XML/CSV kaynaklarından güvenli, tekrar çalıştırılabilir ve izlenebilir ürün aktarımı. Çekirdek ürün tablolarına doğrudan SQL ile ürün yazılmaz.

## Yönetim akışı
1. Tedarikçi ekle.
2. XML URL'si (HTTP/HTTPS) veya XML/CSV dosyası ekle; kimlik doğrulama gerekiyorsa HTTPS Basic Auth.
3. Bağlantıyı test et, XML yapısını tara ve örnek ürünleri göster.
4. Alanları otomatik eşleştir; gerekirse elle düzenle.
5. Kategori/marka eşleştirmesi ve genel/kategori bazlı kâr kuralı tanımla.
6. Önizleme ve doğrulama raporunu incele.
7. Seçili ürünleri veya tüm kataloğu tek işlemle kuyruğa al.
8. İlerleme, oluşturulan/güncellenen/atlanan/hatalı kayıtları izle; hata kayıtlarını yeniden dene.
9. Zamanlanmış senkronizasyonla fiyat, stok, görsel ve seçilen alanları güncelle.
10. XML/CSV dışa aktarımı için filtreli besleme oluştur.

## Ürün alanları
Ürün adı, açıklama/kısa açıklama, SKU/stok kodu, barkod/EAN/GTIN, fiyat, indirimli/eski fiyat, stok, KDV, marka, kategori, görseller, desi, kargo ücreti/süresi, SEO alanları ve ürün aktifliği. Desteklenen XML şeması elverdiğinde çoklu görsel ve varyantlar (renk/beden/özellik, SKU, barkod, fiyat, stok) aktarılır.

## Fiyat kuralları
- Genel kâr yüzdesi ve sabit tutar.
- Kategori bazlı kâr yüzdesi ve sabit tutar; kategori kuralı genel kuralı geçersiz kılar.
- Tedarikçi/bağlantı bazlı istisna.
- Kural değiştiğinde yalnızca bu XML kaynağına bağlı ürünlerin fiyatlarını önizleme sonrası toplu yeniden hesaplama.
- Fiyatın negatif/0 olması, döviz/KDV karışıklığı ve taşma durumlarında kayıt reddedilir.
- Formül ve yuvarlama yöntemi testlerle sabitlenir; alış fiyatı ayrı saklanır.

## Eşleştirme ve tekrar güvenliği
- Öncelik: bağlantı + tedarikçi ürün kimliği; ardından SKU; barkod; kullanıcı seçerse kontrollü ek eşleştirme.
- Aynı ürün ikinci kez gelirse kopya oluşturulmaz.
- Kullanıcı hangi alanların güncelleneceğini seçer.
- Tedarikçi XML'inde olmayan alanlar varsayılan olarak silinmez.
- Kaynaktan kaybolan ürünler otomatik silinmez; ayrı 'kaynakta bulunamadı' raporu ve isteğe bağlı pasife alma politikası kullanılır.
- Bir çalıştırma yarıda kalırsa kaldığı yerden devam eder; aynı bağlantı için eşzamanlı iki çalıştırma engellenir.

## Güvenlik ve dayanıklılık
- SSRF koruması: localhost/özel IP/link-local/metadata adresleri, DNS rebinding ve güvensiz yönlendirmeler engellenir; her yönlendirme yeniden doğrulanır.
- TLS sertifika doğrulaması açık; zaman, boyut, yönlendirme, indirme ve ayrıştırma sınırları.
- XXE/DOCTYPE/ENTITY engeli; XMLReader ile akışlı ayrıştırma, derinlik ve kayıt limitleri.
- Dosya yüklemede uzantı/MIME/içerik kontrolü, rastgele dosya adı, web erişimine kapalı depolama.
- CSRF, yönetici/satıcı rol kontrolleri, satıcılar arası veri izolasyonu, parametreli sorgular.
- Kimlik bilgileri şifreli saklanır; loglarda parola/token/özel URL sırları maskelenir.
- İçe aktarma işlemleri loglanır; mümkün olduğunda fiyat/stok geçmişi tutulur.

## Arayüz bölümleri
Özet, Tedarikçiler, XML Kaynakları, Alan Eşleştirme, Kategori/Marka Eşleştirme, Kâr Kuralları, Ürün Önizleme, Aktarım Kuyruğu, Senkronizasyon Planı, Hata Günlüğü, Dışa Aktarım, Ayarlar.

## Test ve kabul kapıları
- Saf birim testleri: eşleştirme, para/ondalık ayrıştırma, kâr hesapları, varyantlar, tekrar kayıt tespiti.
- Güvenlik testleri: SSRF/redirect/DNS, XXE, boyut/derinlik, kötü amaçlı dosya ve CSRF/rol kontrolleri.
- Entegrasyon testleri: gerçek Frisay 3.1 Product::save ve ProductVariation kayıt/güncelleme akışı, kategori/marka, görsel indirme.
- Uçtan uca: gerçekçi tedarikçi XML örnekleri, MySQL, dosya ve URL kaynakları, yarıda kesilme/devam, cron, tekrar çalıştırma.
- Canlıya geçişten önce yedek, test raporu, geri alma planı ve yönetici onayı.
- Sıfır stok test davranışı yalnızca test modunda 5–10 geçici stok ataması olarak sunulur; üretimde tedarikçi stok değeri aynen korunur.

## Açık teknik doğrulamalar
- Frisay 3.1'in gerçek ürün kayıt alanları, zorunlu alanları, resim depolama yöntemi ve varyant API'si entegrasyon testleriyle doğrulanmalı.
- Tedarikçi XML'leri farklı yapıda olduğundan her XML'in kesin otomatik eşleşeceği vaadi verilmez; otomatik algılama + kullanıcı eşleştirmesi + önizleme sağlanır.
- Otomatik senkronizasyon için sunucuda cron görevi gerekir; cron olmadan zamanlanmış görev çalışmaz.