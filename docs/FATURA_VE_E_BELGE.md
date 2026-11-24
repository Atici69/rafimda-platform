# Fatura ve E-Belge Yönetimi (rafimda-invoice v0.1.0)

> **Canlı gönderim KAPALI.** Yalnızca *test bağdaştırıcısı* çalışır: GİB'e/entegratöre bağlanmaz, "TST" serili ve "TEST – MALİ DEĞERİ YOKTUR" yazan belge üretir.
> Gerçek mali belge için: entegratör sözleşmesi + test hesabı + bağdaştırıcının gerçek API'ye göre yazılıp test edilmesi + işletme sahibinin yazılı onayı + mali müşavir doğrulaması.
> Vergisel kurallar (kesim anı, KDV, belge türü, iade belgesi) mali müşavir onayı olmadan kesin kabul edilmemiştir; belirsiz durumlar "taslak/hazır değil" bırakılır.

## Pazaryeri ayrımı
| Tür | Düzenleyen | Alıcı | Ne zaman |
|---|---|---|---|
| Satıcı satış faturası (`sale`) | **Satıcı** (kendi entegratörüyle) | Müşteri | Sipariş, satıcı başına bölünür |
| Rafımda satış faturası (`direct`) | Rafımda | Müşteri | Yalnızca satıcısı olmayan (Rafımda'nın kendi) ürünler |
| Hizmet/komisyon faturası (`service`) | Rafımda | Satıcı | Dönem (YYYY-AA) sonunda, teslim edilmiş satıcı siparişlerinin komisyonu |
| Manuel (`manual`) | Rafımda veya satıcı | Serbest | Elle |
| İade belgesi kaydı (`return`) | — | — | Gönderilmiş faturaya bağlı kayıt; belge türü mali müşavir kararı |

Her siparişin faturası Rafımda adına **kesilmez**. Mükerrer engeli: `idem_key` benzersiz; gönderilmiş (ETTN'li) belge tekrar gönderilmez.

## Akış
1. **Tetikleyici** (Ayarlar): *Yalnızca elle* (varsayılan) / ödeme alındığında / kargoda / teslimde. Sipariş oluşması tek başına kesim zamanı sayılmaz.
2. Sipariş durumu kontrolü (iptal/iade siparişe satış faturası yok) → düzenleyene göre bölme → alıcı bilgisi doğrulama (kurumsal: VKN/TCKN + vergi dairesi).
3. **KDV**: ürünün KDV alanı; yoksa *Varsayılan KDV* ayarı; o da yoksa fatura **taslak** kalır. Kodda sabit oran yok.
4. **Belge türü**: kurumsal alıcı bağdaştırıcının mükellef sorgusuna göre e-Fatura/e-Arşiv; sorgulanamazsa varsayım yapılmaz (elle seçilir). Bireysel → e-Arşiv
   (TCKN yoksa `11111111111` — yaygın uygulama, mali müşavirle doğrulayın).
5. Gönderim → yanıt (kabul/ret/hata) → ETTN ve numara siparişe bağlanır → PDF (yetkisiz erişime kapalı `storage/`) → müşteri/satıcıya bildirim.
6. **İptal**: belge silinmez, durum "İptal edildi" ve gerekçe kaydı. **İade**: ilişkili kayıt açılır, orijinal korunur.

## Ekranlar
- Yönetim → **Fatura ve E-Belge**: Faturalar (süzgeç, detay, gönder, tür seç, iptal, iade), Fatura hazırla (siparişler, dönem komisyon faturası, manuel), Entegratör (hesap, şifre şifreli, bağlantı testi), Ayarlar (tetikleyici, KDV, otomatik gönderim, canlı kilit), Hata kayıtları.
- Satıcı paneli → **Faturalar**: firma/vergi bilgisi, e-Fatura mükellefiyeti, entegratör bağlantısı + test, fatura hazırla, geçmiş, PDF, iptal. Yalnızca kendi faturaları.
- Müşteri → **Hesap merkezi › Faturalarım**: no, tarih, durum, PDF (yalnızca kendi siparişleri).

## Entegratörler
`lib/adapters/RiAdapter.php` arayüzü. Mevcut: `test` (çalışır, mali değeri yok), `qnb` (QNB eSolutions — **iskelet, gönderim yapmaz**, bağlantı testi açıkça başarısız döner).
FriSay'in `bifatura` modülü tek satıcılı mağaza içindir (her siparişi tek firma adına keser, 10 haneli VKN'yi otomatik e-Fatura sayar, KDV yoksa %20 varsayar); pazaryeri faturalandırmasında kullanılmamalıdır.

Testler: `modules/rafimda-invoice/tests/run.php` (65).
