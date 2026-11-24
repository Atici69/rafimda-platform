# Soru-Cevap, Değerlendirme, Mağaza Vitrini, Destek ve Bildirimler

Kod: `modules/rafimda-vendor` (migration 007). FriSay `question` ve `reviews` modülleri **kurulu olmalıdır** (ürün soruları/yorumları onların tablolarındadır);
bu modüllere yalnızca `class_exists('RvQA'|'RvReviews', false)` korumalı kanca satırları eklendi — Rafımda modülü yoksa davranışları değişmez.

| Özellik | Nerede | Kural |
|---|---|---|
| Ürün soruları | Ürün sayfası (FriSay) → Satıcı paneli **Soru-Cevap** | Satıcı yalnızca kendi ürünlerinin sorusunu görür/yanıtlar; yanıt "Satıcı yanıtı · Mağaza" etiketiyle; telefon/e-posta/IBAN/TCKN/bağlantı herkese açık gösterimde maskelenir; soru sahibine bildirim |
| Mağaza soruları | `/magaza?s=slug&tab=questions` | Giriş zorunlu, kendi mağazasına soru yok, saatte 5, çoklu bağlantılı spam kaydedilmez |
| Moderasyon | Yönetim → Satıcılar → **Soru · Yorum · Destek** | `RAFIMDA_QA_MODERATION=1` ise yanıtlar onaydan sonra yayınlanır |
| Ürün yorumu | Ürün sayfası (FriSay) veya `/degerlendir?order=ID` | Yorumlar onaydan sonra yayında (FriSay davranışı); **doğrulanmış satın alma** yalnızca teslim edilmiş (durum 4) siparişte; mağaza personeli kendi ürününü değerlendiremez; ürün başına tek yorum; `RAFIMDA_REVIEW_PURCHASE_ONLY=1` ile yalnızca teslim alınan ürün |
| Fotoğraflı yorum | `/degerlendir` | En fazla 3, 3 MB, gerçek görsel kontrolü + yeniden kodlama (EXIF/konum silinir) |
| Mağaza değerlendirmesi | `/degerlendir` | Yalnızca o mağazanın ürününü içeren teslim edilmiş sipariş; sipariş başına bir kez; kendi mağazası yok; onaydan sonra puana dahil |
| Faydalı buldum | Ürün ve mağaza yorumları | Kişi başı bir oy, kendi yorumuna oy yok |
| Satıcı yanıtı | Satıcı paneli **Değerlendirmeler** | Yalnızca kendi ürününün/mağazasının yorumu; yorum sahibine bildirim |
| Mağaza vitrini | `/magaza?s=slug` | Yalnızca aktif mağaza; logo/kapak (yeniden kodlanır), hakkında, kargo/iade notu, puan (yalnız onaylı), yeni ürünler, çok satanlar, değerlendirmeler, soru-cevap, takip |
| Ürün sayfası satıcı kutusu | `product_detail` kancası | Satıcı adı, puan, takipçi, kargo/iade notu, "Rafımda aracı hizmet sağlayıcıdır" |
| Destek | Müşteri: `/hesap-merkezi#destek`; Satıcı: panel **Destek**; Yönetici: **Soru · Yorum · Destek** | Talep no (DTyy-000001), kategori, durum (Açık/Yanıtlandı/Yanıt bekleniyor/Kapandı), geçmiş; izolasyon; başkasının siparişi bağlanamaz |
| Bildirimler | Satıcı paneli **Bildirimler**, müşteri `/hesap-merkezi` | Her bildirim veritabanında; e-posta yalnızca FriSay Mail yapılandırılmışsa (aksi halde `not_configured` kaydı); **SMS altyapısı yok** |
| İzinler | `/hesap-merkezi#izinler` | Ticari ileti (e-posta/SMS/telefon) ve açık rıza ayrı ayrı verilir/geri alınır (rafimda-legal kuruluysa) |

Testler: `modules/rafimda-vendor/tests/run_engage.php` (83).
