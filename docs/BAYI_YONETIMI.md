# Rafımda Bayi Yönetimi (rafimda-dealer v0.1.0)

Bu belge, bayi modülünün **kodda gerçekten uygulanan** kurallarını anlatır. Her kuralın karşılığı `modules/rafimda-dealer/tests/` altındaki testlerle doğrulanmıştır
(SQLite + gerçek Smarty; **gerçek MySQL/FriSay/tarayıcı ile henüz denenmedi** — bkz. "Doğrulama durumu").

> **Muhasebe pasiftir.** Modülde ödeme, banka, fatura, muhasebe fişi ve cari hesap **yoktur**; yetki matrisinde de bu alanlara ait hiçbir yetki tanımlı değildir
> (testle doğrulanır). Abonelik ve komisyon yalnızca **kayıt/hesaplamadır**; para hareketi yapılmaz, tahsilat ve ödeme platform dışında yapılır.

## 1. Kavramlar ve ilişkiler

```
Platform Yöneticisi
 └─ Bayi (ana bayi, seviye 1)          ← müşteri getirir, komisyon kazanır
     └─ Alt Bayi (seviye 2)           ← yalnızca bir ana bayiye bağlı
         └─ (alt bayinin altına yeni bayi EKLENEMEZ)

Müşteri (mağaza hesabı) ── tek bir bayiye veya alt bayiye bağlanır (ilk bağlantı kalıcıdır)
```

| Kavram | Kural |
|---|---|
| Hesap | Bayi, mağazanın mevcut müşteri hesabıdır (`users`); ayrı parola yoktur. Bir hesap yalnızca **bir** bayi kaydına sahip olabilir. |
| Seviye | `id_parent = 0` ana bayi, `> 0` alt bayi. Üç seviye yoktur: alt bayinin altına bayi başvurusu reddedilir. |
| Referans kodu | Her bayiye 8 karakterli benzersiz kod verilir (`rd_dealers.referral_code`). |
| Müşteri ataması | (a) `/bayi-ref?kod=KOD` bağlantısı kodu oturuma ve 30 gün geçerli çerezde saklar; müşterinin **ilk siparişinde** o bayiye bağlanır. (b) Yönetici elle atar. Müşteri bir kez bağlanınca değişmez; bayi kendi kendine müşteri olamaz; alt bayinin müşterisi üst bayisinin sahibi olamaz. |
| Alt bayi kontenjanı | Üst bayinin **paketindeki** `max_sub_dealers` ile sınırlıdır (0 = alt bayi alamaz). Bekleyen/aktif/askıdaki alt bayiler kontenjana sayılır. |
| Üst bayi denetimi | Ana bayi, kendi alt bayisini **durdurabilir / yeniden başlatabilir** (`paused_by_parent`). Durdurulan alt bayi panele giremez ve komisyon üretmez. |

## 2. Başvuru → onay → yetkilendirme

1. **Başvuru** — `/bayi-basvuru` (davet: `/bayi-basvuru?ust=ÜSTKOD`). Form: firma, yetkili, telefon, e-posta, il, vergi dairesi/no, not, koşulların onayı.
   Oturum açık değilse hesap da aynı formda oluşturulur; **kayıtlı e-postada yanlış parolayla başvuru devralınamaz**. Başvuru `pending` (bekliyor) başlar.
2. **İnceleme** — Yönetim → *Bayiler (Rafımda)* → **Başvurular**. Yönetici `active` (onayla), `rejected` (gerekçeyle reddet) veya `suspended` yapar.
   Her durum değişikliği `rd_audit_log`'a yazılır (kim, ne zaman, gerekçe).
3. **Yetkilendirme** — Onayla birlikte bayi `/bayi-giris` ile panele girer. Bekleyen/reddedilen/askıdaki bayi **yalnızca durum sayfasını** görür (gerekçe dahil).
4. **Oran ve paket** — Yönetici bayiye özel oranları ve paketi belirler (aşağıda).

Durumlar: `pending` Beklemede · `active` Aktif · `suspended` Askıda · `rejected` Reddedildi. Bayi **işlem yapabilir** (operational) ancak kendisi aktifse, durdurulmamışsa ve (alt bayiyse) üst bayisi de aktifse.

## 3. Yetkilendirme (RBAC)

FriSay çekirdeğinde ayrı yönetici rolleri yoktur (yönetici tektir); bu nedenle roller şöyledir. Matris `lib/RdPerm.php` içindedir ve **her istekte sunucu tarafında** uygulanır (menüden gizlemek tek başına yeterli sayılmaz). `tests/run_rbac.php` (74 test) matrisi, panel menüsünü ve yönetim işlemlerini birbirine karşı doğrular.

| Yetki | Ziyaretçi | Alt bayi | Bayi | Yönetici |
|---|:-:|:-:|:-:|:-:|
| Başvuru yapma (`apply.submit`) | ✔ | | | |
| Genel Bakış, Müşteriler, Kazançlar, Abonelik, Raporlar, Profil | | ✔ | ✔ | |
| Alt Bayiler bölümü (`panel.subdealers`) | | ✖ | ✔ | |
| Alt bayiyi durdur/başlat (`subdealers.manage`) | | ✖ | ✔ (yalnız kendi alt bayisi) | |
| Komisyonları CSV dışa aktarma (`earnings.export`) | | ✔ (kendi) | ✔ (kendi) | |
| Paket talebi (`subscription.request`) | | ✔ | ✔ | |
| Başvuru onay/ret, askıya alma (`dealers.review`, `dealers.suspend`) | | | | ✔ |
| Bayiye özel oran (`dealers.rates`) | | | | ✔ |
| Paket tanımı / atama (`plans.manage`, `subscriptions.assign`) | | | | ✔ |
| Komisyon kaydını onay/iptal (`commissions.moderate`) | | | | ✔ |
| Müşteriyi elle bayiye atama (`customers.attach`) | | | | ✔ |
| Varsayılan oran, migration (`settings.manage`) | | | | ✔ |
| Platform raporları (`reports.platform`) | | | | ✔ |

Veri izolasyonu: panelin her sorgusu oturumdaki bayi kimliğiyle sınırlıdır; başka bayinin müşteri/komisyon/alt bayisi görülemez ve değiştirilemez (testle doğrulanır, örn. başkasının alt bayisini durdurma denemesi reddedilir).

### Satıcı (rafimda-vendor) ile ilişkisi
Bayi ve satıcı **ayrı sistemlerdir**. Satıcı rolleri (Mağaza Sahibi, Yönetici, Katalog, Sipariş, Görüntüleyici) `RvPerm` ile yönetilir. Bir kişi aynı hesapla hem satıcı hem bayi olabilir; yetkiler birbirine geçmez.

## 4. Komisyon ve satış takibi

**Oran önceliği** (`RdCommission::rateFor`): bayiye özel oran (> 0) → geçerli paket oranı → varsayılan oran (`RAFIMDA_DEALER_DEFAULT_COMMISSION`, kurulumda **0**).
Oran 0 ise komisyon kaydı üretilmez.

**Hesaplama** (`order.placed` ile otomatik; hata sipariş akışını bozmaz):
- *Doğrudan komisyon* = sipariş toplamı × bayinin oranı — müşterinin bağlı olduğu bayiye.
- *Üst bayi payı* (`override`) = sipariş toplamı × alt bayinin `parent_share_rate` değeri — alt bayinin müşterisinden doğan siparişte üst bayiye. Yönetici oranlarda `komisyon + üst pay ≤ 100` kuralını görür.
- Aynı sipariş/bayi/tür için yalnızca **bir** kayıt oluşur (mükerrer yok). Toplamı 0 olan, iptal/iade (durum 5/6) siparişte kayıt yok.

**Durum akışı** (`order.updated`): sipariş *teslim edildi* (4) → komisyon `approved`; *iptal/iade* (5/6) → `cancelled`. Yönetici elle de değiştirebilir (`commission_set`).
Komisyon yalnızca **kayıt**tır; ödeme yapıldığını göstermez. Değiştirilen oranlar yalnızca **yeni** siparişlere uygulanır.

**Takip ekranları**
- Bayi paneli: Genel Bakış (özet), Müşteriler (e-posta maskeli), Kazançlar (durum süzgeci, CSV), Performans Raporları (12 aylık, en iyi müşteriler, alt bayi performansı), Profil ve Referans (referans ve davet bağlantıları).
- Yönetim: **Komisyonlar** ve **Raporlar** sekmeleri (platform geneli).

## 5. Abonelik ve paketler

Paketler (`rd_plans`): aylık fiyat (yalnızca bilgi), komisyon oranı, alt bayi kontenjanı. Bayi panelden **talep** açar (aynı anda tek bekleyen talep);
yönetici talebi onaylar veya doğrudan paket atar (süreli/süresiz). **Ödeme alınmaz.** Süresi dolan paket etkisini yitirir (oran varsayılana döner, kontenjan 0 olur; mevcut alt bayiler silinmez).

## 6. Yönetim ekranı (Yönetim → Bayiler (Rafımda))

Sekmeler: **Başvurular · Bayiler · Paketler · Abonelikler · Komisyonlar · Raporlar · Ayarlar**. Ayarlar: varsayılan komisyon oranı ve bekleyen migration düğmesi.

## 7. Kurulum ve geri dönüş

Sıra (staging'de): `rafimda-vendor` → … → `rafimda-dealer` (bkz. `docs/CANLI_KURULUM_NATRO.md`). Kurulum yalnızca `CREATE TABLE IF NOT EXISTS` ile 7 yeni tablo oluşturur
(`rd_migrations, rd_dealers, rd_plans, rd_subscriptions, rd_customers, rd_commissions, rd_audit_log`) ve `RAFIMDA_DEALER_DEFAULT_COMMISSION=0` ayarını ekler.
Çekirdek tablolara dokunmaz. Modül kapatılırsa rotalar durur; **veri silinmez** (`uninstall.sql` zararsızdır).

## 8. Doğrulama durumu

| Alan | Durum |
|---|---|
| Başvuru/onay/komisyon/RBAC mantığı | **Test edildi** (SQLite; `run.php` 93, `run_flows.php` 46, `run_rbac.php` 74) |
| Şablonlar (Smarty), XSS kaçışı | **Test edildi** |
| Gerçek MySQL, gerçek FriSay, gerçek oturum/çerez, canlı tarayıcı | **Test EDİLMEDİ** — staging'de yapılmalı |
| Ödeme / muhasebe | **Yok** (bilinçli) |
