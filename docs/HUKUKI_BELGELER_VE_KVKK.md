# Hukuki Belgeler ve KVKK (rafimda-legal v0.1.0)

> Metinler **TASLAKTIR**, hukuki görüş değildir. Her belge "Hukuki inceleme gerekli" işaretiyle kurulur. Yayından önce avukat / KVKK danışmanı onayı gerekir.
> Şirket bilgileri **uydurulmaz**: Yönetim → *Hukuki Belgeler ve KVKK* → **Şirket bilgileri** sekmesinde doldurulur; boş alanlar sitede `[DOLDURULMADI: …]` olarak görünür.

## Belgeler (19)
KVKK Aydınlatma Metni · Açık Rıza Metni · Gizlilik Politikası · Çerez Politikası · Çerez Tercihleri · Kullanım Koşulları · Üyelik Sözleşmesi ·
Satıcı Sözleşmesi · Pazaryeri Aracılık Sözleşmesi · Ön Bilgilendirme Formu · Mesafeli Satış Sözleşmesi · İptal ve İade Politikası ·
Cayma Hakkı Bilgilendirmesi · Teslimat ve Kargo Politikası · Şikâyet ve Uyuşmazlık Süreci · Ticari Elektronik İleti Onay Metni ·
Kişisel Veri Başvuru Süreci · **Bayilik ve Alt Bayilik Sözleşmesi (TASLAK, PASİF – gösterilmez, bayilik etkinleşmeden yayınlanamaz)**.

Aracı / satıcı ayrımı: satıcı mağazanın ürünlerinde satış sözleşmesi **alıcı ile satıcı** arasında kurulur; Rafımda **aracı hizmet sağlayıcıdır**. Rafımda'nın kendi sattığı ürünlerde satıcı şirketin kendisidir.

## Kayıtlar
| Kayıt | Ne zaman | İçerik |
|---|---|---|
| Kabul (`rl_acceptances`) | Üye kaydı (üyelik + KVKK okundu), satıcı başvurusu (satıcı + aracılık + KVKK, başvuru no ile), sipariş (ön bilgilendirme + mesafeli satış, `order:ID`) | belge, **o anki sürüm**, kullanıcı, bağlam, IP/UA **özeti** |
| İzin (`rl_consents`) | Kayıt formu (isteğe bağlı kutular), çerez bildirimi/tercih sayfası | tür, verildi/geri alındı, kaynak, belge sürümü; ekleme-yalnızca |

- Aydınlatma (bilgilendirme), açık rıza ve ticari ileti izni **ayrı** kutulardır; zorunlu olmayanlar **önceden seçili gelmez**.
- Yayınlanmış sürüm değiştirilemez; her kayıt yeni sürüm oluşturur (SHA-256 özetli).
- Çerez: zorunlu olmayanlar varsayılan kapalı; **Google Analytics** ve **Meta Pixel** modülleri izin yoksa betik basmaz.
- Ödeme sayfasındaki FriSay CMS metinleri (`mesafeli-satis`, `gizlilik`) yalnızca yönetici düğmesiyle yayındaki sürümden güncellenir.

## Bilinen sınırlar
- Sipariş bazında satıcı bilgileriyle doldurulmuş mesafeli satış sözleşmesi/PDF üretimi **yok** (genel metin + kabul kaydı var).
- İYS (İleti Yönetim Sistemi) entegrasyonu **yok**; izinler yerelde kaydedilir, İYS bildirimi elle/entegrasyonla yapılmalıdır.
- Hesabım sayfasında izin geri alma ekranı **yok** (kayıt ve çerez sayfası var); çekirdek hesap sayfası değiştirilmedi.
- E-posta doğrulamalı kayıtta onaylar 30 dk içinde ilk girişte bağlanır; daha geç girişte üyelik kabulü yeniden alınmalıdır.

Testler: `modules/rafimda-legal/tests/run.php` (51).
