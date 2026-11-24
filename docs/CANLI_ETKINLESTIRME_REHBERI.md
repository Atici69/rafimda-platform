# Canlıda Rafımda modüllerini etkinleştirme (340072c sonrası)

## Neden sitede değişiklik görünmedi?
1. Dosyaları yüklemek yetmez: FriSay modülleri yönetici panelinden **kurulup etkinleştirilene** kadar rota, menü ve kanca üretmez. Kurulum modül migration'larını çalıştırır = canlı veritabanına yazma.
2. Mevcut tema (theme4) `home_showcase` kancasını çizmez; vitrin modülünün varsayılanı `home_bottom` olarak düzeltildi.
3. Sitede `/satici-giris` gibi adreslere bağlantı yoktu; `rafimda-theme` modülü footer, mobil menü ve kayıt sayfasına ekler.
4. Rafımda renkleri/hero hiçbir yerde tanımlı değildi; `rafimda-theme` modülü CSS ile ekler.

## Sıra (yönetici paneli > Modüller)
0. **Yedek alın** (dosyalar + veritabanı dökümü). Sunucu dışında saklayın.
1. `rafimda-theme` kur + etkinleştir (migration yok, risksiz). Ana sayfa hero'su ve renkler gelir. Önbellek varsa temizleyin.
2. `rafimda-vendor` kur + etkinleştir (**rafimda_* tabloları oluşturur**; onayınızla). `/satici-giris`, `/satici-kayit`, `/satici-panel` açılır; footer/mobil menüde bağlantılar belirir. Müşteri oturumu (Customer::login) ortaktır.
3. `xml-hub`, `marketplace-hub` (xh_*, mh_* tabloları) ve `rafimda-showcase` kur + etkinleştir. Yönetici menüsünde "XML Hub" ve "Pazaryeri Hub" görünür; Rafımda Tema sayfası modül durumunu listeler.
4. Gizli anahtarları (XML_HUB_SECRET vb.) panelden girin; koda/depoya yazmayın.

Alternatif (modülsüz): Tema ayarlarından ana/ikincil rengi `#0a2540` / `#ff7a1a` yapın.

## Doğrulananlar / doğrulanmayanlar
- Test düzeyinde doğrulandı (1003 test): modül yükleme kuralları, kanca listesi, şablon kaçışı, ölü bağlantı üretmeme, ayar temizleme.
- **Gerçek sunucuda, gerçek MySQL'de ve gerçek theme4 çıktısında denenmedi.** Görsel sonuç sizin kontrolünüzle doğrulanmalıdır (mobil dahil).
