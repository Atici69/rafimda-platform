# Sosyal Medya İçerik ve Yayın Sistemi (rafimda-social v0.1.0)

> **Otomatik yayın KAPALI ve henüz uygulanmadı.** İçerik üretimi, önizleme, onay ve takvim çalışır. Instagram / TikTok / YouTube için resmî
> OAuth yetkilendirme adresleri hazırdır; **kod değişimi, medya yükleme ve yayın çağrıları uygulanmadı** (uygulama kaydı + platform incelemesi +
> test hesabı gerekir). Bu yüzden hesaplar "bağlı" görünmez ve yayın "başarısız: henüz uygulanmadı" olarak açıkça raporlanır.
> Şifreyle bot girişi, otomatik beğeni/takip/yorum/mesaj **yoktur**.

| Bileşen | Durum | Not |
|---|---|---|
| Metin, başlık, hashtag | ✅ | Yalnızca ürünün gerçek alanları; indirim yalnızca üründe gerçek eski fiyat varsa |
| Instagram gönderi (1080×1080) / hikâye (1080×1920) görseli | ✅ | GD + DejaVu Sans (Türkçe), lacivert/turuncu şablon |
| Dikey kısa video (Reels / TikTok / Shorts, 1080×1920 H.264) | ✅ ffmpeg varsa | Natro paylaşımlı hostingde ffmpeg/proc_open genellikle **yoktur** → kare dizisi (storyboard) üretilir |
| Yapay zekâ ile video | 🔌 Altyapı | `RsoContent::$videoRenderer` değiştirilebilir |
| Takvim (gün/hafta/ay), kuyruk, durumlar | ✅ | Taslak · Onay bekliyor · Yayına hazır · Yayınlandı · Başarısız · İptal · Beklemede |
| Türkiye saati | ✅ | Europe/Istanbul, sunucu saat diliminden bağımsız |
| Tekrar engeli | ✅ | Aynı ürün+platform N gün (varsayılan 14); aynı hesap aynı dakika |
| Otomatik ürün seçimi | ✅ | Yeni, çok satan, kampanyalı (gerçek eski fiyat), öne çıkan (vitrin), stokta |
| Yayın öncesi kontrol (cron) | ✅ | Pasif ürün → iptal; stok 0 → bekletme; fiyat değişti → bekletme (eski fiyat yayınlanmaz); medya yok → bekletme |
| Satıcı paneli | ✅ | Yalnızca kendi ürünleri/hesapları; aylık içerik limiti (paketlere bağlanabilir); içerikler yönetici onayıyla |
| Resmî OAuth (Meta / TikTok / Google) | 🔶 Yarım | Yetkilendirme adresi + tek kullanımlık state; token şifreli saklanır; **kod değişimi uygulanmadı** |
| Otomatik yayın | ⛔ Kapalı | `RAFIMDA_SOCIAL_PUBLISH` onay ifadesiyle açılsa bile resmî yayıncı uygulanmadan paylaşım yapılmaz |
| İstatistik raporu | 🔶 | API bağlantısı yokken veri gösterilmez (tahmini rakam yok) |

Cron: `php modules/rafimda-social/cron.php` (5 dk). Font lisansı: `assets/fonts/LICENSE-DejaVu.txt` (Bitstream Vera / DejaVu, serbest dağıtım).
Testler: `modules/rafimda-social/tests/run.php` (60).
