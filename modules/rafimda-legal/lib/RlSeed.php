<?php

/**
 * Hukuki belge TASLAKLARI (ilk kurulum içeriği). Bu metinler hukuki görüş DEĞİLDİR; yayından önce avukat/KVKK danışmanı tarafından
 * incelenmeli ve şirket bilgileri yönetim panelinden doldurulmalıdır. Şirket bilgileri UYDURULMAZ: {{...}} yer tutucuları panelde girilen
 * gerçek değerlerle değiştirilir; boş olanlar sayfada "[DOLDURULMADI: …]" olarak işaretlenir.
 *
 * Biçim: "## " ana başlık, "### " alt başlık, "- " madde, boş satır paragraf. HTML kabul edilmez (güvenlik).
 */
final class RlSeed
{
	public const HEADER = "Bu metin {{SURUM}} sürümüdür; yürürlük tarihi: {{YURURLUK_TARIHI}}.\n\n";

	/** @return array<int,array{slug:string,title:string,grp:string,status:string,review:string,body:string}> */
	public static function documents(): array
	{
		$k = "Veri sorumlusu: {{SIRKET_UNVANI}} (MERSİS: {{MERSIS_NO}}, adres: {{ADRES}}, e-posta: {{EPOSTA}}, KEP: {{KEP}}).";

		return [
			['slug' => 'kvkk-aydinlatma', 'title' => 'KVKK Aydınlatma Metni', 'grp' => 'kvkk', 'status' => 'published', 'review' => 'Veri kategorileri, aktarım alıcıları ve saklama süreleri gerçek işleyişe göre doğrulanmalı.', 'body' => self::HEADER . <<<T
## Kişisel Verilerin Korunması Hakkında Aydınlatma Metni
6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") 10. maddesi uyarınca, {{SITE_ADRESI}} ("Rafımda") üzerinden işlenen kişisel verileriniz hakkında sizi bilgilendiririz. $k

Bu metin bir **aydınlatma** metnidir; onay vermeniz gerekmez. Açık rızaya dayanan işlemler ayrı "Açık Rıza Metni" ile, ticari elektronik ileti izni ayrı "Ticari Elektronik İleti Onay Metni" ile alınır.

## İşlenen Kişisel Veriler
- Kimlik: ad, soyad; satıcı başvurularında T.C. kimlik numarası veya vergi kimlik numarası.
- İletişim: e-posta, telefon, teslimat ve fatura adresi.
- Müşteri işlem: sipariş, sepet, iade ve iptal kayıtları, destek talepleri, ürün soruları ve değerlendirmeleri.
- İşlem güvenliği: IP adresi, oturum ve giriş kayıtları, tarayıcı bilgisi.
- Pazarlama (yalnızca izin verirseniz): ticari ileti tercihleri, çerez tercihleri.

## İşleme Amaçları ve Hukuki Sebepler
- Üyelik ve sipariş süreçlerinin yürütülmesi: sözleşmenin kurulması ve ifası (KVKK m.5/2-c).
- Fatura, muhasebe ve yasal saklama yükümlülükleri: kanunlarda açıkça öngörülme ve hukuki yükümlülük (m.5/2-a, ç).
- Satıcı başvurularının değerlendirilmesi ve pazaryeri aracılık hizmeti: sözleşmenin ifası ve meşru menfaat (m.5/2-c, f).
- Bilgi güvenliği ve dolandırıcılığın önlenmesi: meşru menfaat (m.5/2-f).
- Ticari elektronik ileti gönderimi ve zorunlu olmayan çerezler: yalnızca açık rıza / onay ile.

## Aktarım
Siparişinizin ifası için gerekli verileriniz, ürünü satan satıcı mağazaya, kargo firmalarına, ödeme kuruluşlarına ve e-fatura entegratörüne; yasal talepte yetkili kamu kurumlarına aktarılabilir. Yurt dışına aktarım yapılan bir hizmet kullanılıyorsa bu hizmet ve hukuki dayanağı burada ayrıca belirtilmelidir: {{YURT_DISI_AKTARIM}}.

## Toplama Yöntemi
Verileriniz internet sitesi, satıcı paneli, e-posta ve destek kanalları üzerinden elektronik ortamda toplanır.

## Haklarınız
KVKK m.11 uyarınca verilerinizin işlenip işlenmediğini öğrenme, bilgi talep etme, amacını öğrenme, aktarılan kişileri bilme, düzeltme, silme/yok etme, itiraz ve zararın giderilmesini talep etme haklarına sahipsiniz. Başvuru yöntemi için "Kişisel Veri Başvuru Süreci" sayfasına bakınız ({{KVKK_BASVURU_EPOSTA}}).
T],
			['slug' => 'acik-riza', 'title' => 'Açık Rıza Metni', 'grp' => 'kvkk', 'status' => 'published', 'review' => 'Açık rızaya gerçekten ihtiyaç duyulan işlemler (ör. yurt dışı aktarım, pazarlama analizi) netleştirilmeli; gereksizse kaldırılmalı.', 'body' => self::HEADER . <<<T
## Açık Rıza Metni
Bu metin isteğe bağlıdır. Onay vermemeniz üyelik veya alışveriş yapmanıza engel değildir. Onayınızı dilediğiniz zaman "Hesabım" veya {{KVKK_BASVURU_EPOSTA}} üzerinden geri alabilirsiniz.

Aşağıdaki işlemler için açık rızanız istenmektedir:
- Alışveriş tercihlerinize göre kişiselleştirilmiş öneri ve kampanya analizleri yapılması.
- {{YURT_DISI_AKTARIM}}

$k
T],
			['slug' => 'gizlilik-politikasi', 'title' => 'Gizlilik Politikası', 'grp' => 'kvkk', 'status' => 'published', 'review' => 'Kullanılan üçüncü taraf hizmetler (ödeme, kargo, analitik) listelenmeli.', 'body' => self::HEADER . <<<T
## Gizlilik Politikası
Rafımda, kullanıcılarının gizliliğine önem verir. Kişisel verilerin hangi amaçlarla işlendiği "KVKK Aydınlatma Metni"nde açıklanmıştır.

## Güvenlik
- Şifreler geri döndürülemez biçimde (hash) saklanır; çalışanlarımız şifrenizi göremez.
- Site trafiği SSL/TLS ile şifrelenir.
- Kart bilgileri Rafımda'da saklanmaz; ödeme, lisanslı ödeme kuruluşu tarafından alınır.
- Satıcılar yalnızca kendi siparişlerine ait teslimat için gerekli bilgileri görebilir.

## Satıcıların Sorumluluğu
Rafımda bir pazaryeridir. Siparişinizi karşılayan satıcı mağaza, teslimat bilgilerinizi yalnızca siparişin ifası için kullanmakla yükümlüdür.

## Değişiklikler
Bu politika güncellendiğinde yeni sürüm bu sayfada yayınlanır ve sürüm geçmişi saklanır.

İletişim: {{EPOSTA}}
T],
			['slug' => 'cerez-politikasi', 'title' => 'Çerez Politikası', 'grp' => 'kvkk', 'status' => 'published', 'review' => 'Gerçekte kullanılan çerezlerin adı, süresi ve sağlayıcısı tabloya işlenmeli.', 'body' => self::HEADER . <<<T
## Çerez Politikası
Çerezler, ziyaret ettiğiniz sitenin tarayıcınıza kaydettiği küçük dosyalardır.

## Çerez Türleri
- Zorunlu çerezler: oturum, sepet ve güvenlik (CSRF) için gereklidir; kapatılamaz ve onay gerektirmez.
- Analitik çerezler: ziyaret istatistikleri içindir; **yalnızca onay verirseniz** çalışır.
- Pazarlama çerezleri: reklam ölçümü içindir; **yalnızca onay verirseniz** çalışır.

Zorunlu olmayan çerezler varsayılan olarak **kapalıdır**. Tercihlerinizi her zaman "Çerez Tercihleri" sayfasından değiştirebilirsiniz.

## Kullanılan Çerezler
{{CEREZ_LISTESI}}

$k
T],
			['slug' => 'cerez-tercihleri', 'title' => 'Çerez Tercihleri', 'grp' => 'kvkk', 'status' => 'published', 'review' => '', 'body' => self::HEADER . <<<T
## Çerez Tercihleri
Bu sayfadan zorunlu olmayan çerezlere izin verebilir veya izninizi geri alabilirsiniz. Zorunlu çerezler sitenin çalışması için gereklidir ve kapatılamaz.

Tercihiniz tarayıcınızda saklanır; üye girişi yaptıysanız hesabınıza da tarih ve sürüm bilgisiyle kaydedilir.
T],
			['slug' => 'kullanim-kosullari', 'title' => 'Kullanım Koşulları', 'grp' => 'customer', 'status' => 'published', 'review' => 'Sorumluluk sınırları ve fikri mülkiyet hükümleri incelenmeli.', 'body' => self::HEADER . <<<T
## Kullanım Koşulları
{{SITE_ADRESI}} adresindeki Rafımda platformunu kullanarak bu koşulları kabul etmiş olursunuz.

## Platformun Niteliği
Rafımda, bağımsız satıcıların ürünlerini sergilediği bir **elektronik ticaret pazaryeridir**. Ürün sayfasında satıcı olarak bir mağaza adı yazan ürünlerde satıcı o mağazadır; Rafımda **aracı hizmet sağlayıcıdır**. Satıcı olarak "Rafımda" yazan ürünlerde satıcı {{SIRKET_UNVANI}}'dır.

## Kullanıcı Yükümlülükleri
- Hesap bilgilerinizi doğru tutmak ve şifrenizi gizli tutmak.
- Platformu hukuka aykırı, yanıltıcı veya başkalarının haklarını ihlal eden içerik için kullanmamak.
- Ürün soruları ve değerlendirmelerinde kişisel veri, hakaret ve reklam paylaşmamak.

## İçerik
Satıcıların ürün bilgilerinin doğruluğundan ilgili satıcı sorumludur. Rafımda, kurallara aykırı içerikleri yayından kaldırabilir.

## İletişim
{{SIRKET_UNVANI}} · {{ADRES}} · {{EPOSTA}}
T],
			['slug' => 'uyelik-sozlesmesi', 'title' => 'Üyelik Sözleşmesi', 'grp' => 'customer', 'status' => 'published', 'review' => 'Tüm hükümler hukukçu tarafından onaylanmalı.', 'body' => self::HEADER . <<<T
## Üyelik Sözleşmesi
## Taraflar
Bu sözleşme, {{SIRKET_UNVANI}} ("Rafımda") ile {{SITE_ADRESI}} üzerinden üye olan kişi ("Üye") arasında, üyelik formunun onaylanmasıyla elektronik ortamda kurulur.

## Konu
Üyenin platformu kullanma koşulları ile tarafların hak ve yükümlülükleri.

## Üyenin Hak ve Yükümlülükleri
- Üye, verdiği bilgilerin doğru olduğunu kabul eder.
- Üye hesabını üçüncü kişilere devredemez.
- Üye, platform üzerinden yaptığı alışverişlerde ilgili ürünün satıcısıyla ayrıca mesafeli satış sözleşmesi kurar.

## Rafımda'nın Hak ve Yükümlülükleri
- Rafımda, kurallara aykırı kullanımda üyeliği askıya alabilir veya sonlandırabilir.
- Rafımda kişisel verileri KVKK Aydınlatma Metni'ne uygun işler.

## Sona Erme
Üye dilediği zaman üyeliğini sonlandırabilir. Yasal saklama yükümlülüğü bulunan kayıtlar süresince saklanır.

## Uyuşmazlık
Tüketici işlemlerinde, Ticaret Bakanlığınca her yıl belirlenen parasal sınırlar dahilinde Tüketici Hakem Heyetleri ve Tüketici Mahkemeleri yetkilidir.
T],
			['slug' => 'satici-sozlesmesi', 'title' => 'Satıcı Sözleşmesi', 'grp' => 'vendor', 'status' => 'published', 'review' => 'Komisyon, ödeme vadesi, cezai şart ve fesih hükümleri ticari şartlarla birlikte hukukçu tarafından tamamlanmalı.', 'body' => self::HEADER . <<<T
## Satıcı Sözleşmesi
## Taraflar
{{SIRKET_UNVANI}} ("Rafımda") ile satıcı başvuru formunda bilgileri yer alan gerçek veya tüzel kişi ("Satıcı").

## Konu
Satıcının ürünlerini Rafımda pazaryerinde satışa sunması; Rafımda'nın elektronik ticaret aracı hizmet sağlayıcı olarak sunduğu hizmetler.

## Satıcının Yükümlülükleri
- Ürün bilgilerinin (fiyat, stok, özellik, görsel) doğru ve güncel olması.
- Siparişleri süresinde kargoya vermesi ve faturasını kendi adına düzenlemesi.
- Cayma, iade ve garanti yükümlülüklerini 6502 sayılı Kanun ve Mesafeli Sözleşmeler Yönetmeliği'ne uygun yerine getirmesi.
- Müşteri verilerini yalnızca siparişin ifası için kullanması ve KVKK'ya uyması.

## Rafımda'nın Yükümlülükleri
- Pazaryeri altyapısını ve satıcı panelini sunmak.
- Komisyon ve hizmet bedellerini satıcı panelinde şeffaf göstermek.

## Ticari Şartlar
- Komisyon oranı: satıcının paketinde ve panelinde gösterilen oran; kampanya dönemlerinde ilgili kampanya koşulları uygulanır.
- Ödeme vadesi ve hesap kesimi: {{ODEME_VADESI}}.

## Fesih
Taraflardan her biri {{FESIH_SURESI}} önceden bildirimle sözleşmeyi feshedebilir. Rafımda, ağır ihlallerde mağazayı derhal askıya alabilir.
T],
			['slug' => 'pazaryeri-aracilik-sozlesmesi', 'title' => 'Pazaryeri Aracılık Sözleşmesi', 'grp' => 'vendor', 'status' => 'published', 'review' => '6563 sayılı Kanun kapsamındaki aracı hizmet sağlayıcı yükümlülükleri güncel mevzuata göre doğrulanmalı.', 'body' => self::HEADER . <<<T
## Pazaryeri Aracılık Hizmetleri
Rafımda, 6563 sayılı Elektronik Ticaretin Düzenlenmesi Hakkında Kanun kapsamında **elektronik ticaret aracı hizmet sağlayıcı** olarak, satıcılar ile alıcılar arasında elektronik ortamı sağlar.

## Satış İlişkisi
- Satıcı mağazalarının ürünlerinde satış sözleşmesi alıcı ile **satıcı** arasında kurulur.
- Rafımda'nın kendi adına sattığı ürünlerde satıcı {{SIRKET_UNVANI}}'dır ve bu durum ürün sayfasında ayrıca gösterilir.

## Rafımda'nın Rolü
- Siparişin ilgili satıcıya iletilmesi, ödeme kuruluşu üzerinden tahsilatın yönetilmesi, iade ve şikâyet süreçlerinde aracılık.
- Rafımda'nın satıcıya kestiği komisyon ve hizmet faturaları, satıcının alıcıya kestiği satış faturasından **ayrıdır**.

## ETBİS
Rafımda'nın ETBİS kayıt bilgisi: {{ETBIS_NO}}.
T],
			['slug' => 'on-bilgilendirme-formu', 'title' => 'Ön Bilgilendirme Formu', 'grp' => 'order', 'status' => 'published', 'review' => 'Mesafeli Sözleşmeler Yönetmeliği m.5 kapsamındaki tüm alanların sipariş bazında doldurulduğu doğrulanmalı.', 'body' => self::HEADER . <<<T
## Ön Bilgilendirme Formu
Bu form, sipariş onaylanmadan önce Mesafeli Sözleşmeler Yönetmeliği uyarınca bilgilendirme amacıyla sunulur. Sipariş anında aşağıdaki alanlar siparişinizdeki **her satıcı için ayrı ayrı** gösterilir.

## Satıcı Bilgileri
- Unvan / ad soyad, adres, telefon, e-posta, MERSİS veya vergi numarası: siparişteki satıcı mağazanın bilgileri.
- Aracı hizmet sağlayıcı: {{SIRKET_UNVANI}}, {{ADRES}}, {{EPOSTA}}.

## Ürün ve Fiyat
Ürünlerin temel nitelikleri, vergiler dahil toplam fiyat, kargo ücreti ve ödeme şekli sipariş özetinde yer alır.

## Teslimat
Ürün, sipariş özetinde belirtilen süre içinde ve en geç 30 gün içinde teslim edilir.

## Cayma Hakkı
Cayma hakkı ve istisnaları için "Cayma Hakkı Bilgilendirmesi"ne bakınız. Cayma süresi, malın teslim alındığı günden itibaren **14 gündür**.

## Şikâyet
Şikâyet ve itirazlar için "Şikâyet ve Uyuşmazlık Süreci" sayfasına bakınız.
T],
			['slug' => 'mesafeli-satis-sozlesmesi', 'title' => 'Mesafeli Satış Sözleşmesi', 'grp' => 'order', 'status' => 'published', 'review' => 'Sözleşme sipariş bazında satıcı bilgileriyle oluşturulmalı; hükümler hukukçu tarafından onaylanmalı.', 'body' => self::HEADER . <<<T
## Mesafeli Satış Sözleşmesi
## Taraflar
- Satıcı: siparişteki ürünü satan mağaza (bilgileri sipariş özetinde ve sipariş belgesinde yer alır). Rafımda'nın kendi sattığı ürünlerde satıcı {{SIRKET_UNVANI}}'dır.
- Alıcı: siparişi veren kişi.
- Aracı hizmet sağlayıcı: {{SIRKET_UNVANI}}.

## Konu
Alıcının {{SITE_ADRESI}} üzerinden elektronik ortamda sipariş verdiği ürünün satışı ve teslimine ilişkin, 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği uyarınca tarafların hak ve yükümlülükleri.

## Teslimat
Ürün, alıcının bildirdiği adrese, sipariş özetinde belirtilen süre içinde ve her halükârda en geç 30 gün içinde teslim edilir.

## Cayma Hakkı
Alıcı, teslimden itibaren 14 gün içinde gerekçe göstermeksizin cayma hakkına sahiptir. Cayma halinde bedel, bildirimin satıcıya ulaşmasından itibaren 14 gün içinde iade edilir. İstisnalar "Cayma Hakkı Bilgilendirmesi"nde yer alır.

## Uyuşmazlık
Ticaret Bakanlığınca ilan edilen parasal sınırlar dahilinde Tüketici Hakem Heyetleri, aşan durumlarda Tüketici Mahkemeleri yetkilidir.
T],
			['slug' => 'iptal-iade-politikasi', 'title' => 'İptal ve İade Politikası', 'grp' => 'order', 'status' => 'published', 'review' => 'İade kargo ücreti ve süreleri ticari karara göre netleştirilmeli.', 'body' => self::HEADER . <<<T
## İptal ve İade Politikası
## Sipariş İptali
Kargoya verilmemiş siparişlerinizi "Siparişlerim" sayfasından iptal talebiyle iptal edebilirsiniz. Kargoya verilmiş siparişlerde iade süreci uygulanır.

## İade
- İade talebi "Siparişlerim" sayfasından oluşturulur; talep ilgili satıcıya iletilir.
- Ürün, faturası ve mümkünse orijinal ambalajıyla gönderilmelidir.
- İade kargo ücreti: {{IADE_KARGO}}.
- Ücret iadesi, ödeme yaptığınız yönteme, iade onayından itibaren en geç 14 gün içinde yapılır; bankanıza yansıma süresi bankaya göre değişebilir.

## Ayıplı Ürün
Ayıplı ürünlerde 6502 sayılı Kanun m.11'deki seçimlik haklarınız saklıdır.
T],
			['slug' => 'cayma-hakki', 'title' => 'Cayma Hakkı Bilgilendirmesi', 'grp' => 'order', 'status' => 'published', 'review' => 'İstisna listesi Yönetmelik m.15 ile karşılaştırılmalı.', 'body' => self::HEADER . <<<T
## Cayma Hakkı
Mesafeli sözleşmelerde, malın teslim alındığı günden itibaren **14 gün** içinde herhangi bir gerekçe göstermeksizin ve cezai şart ödemeksizin sözleşmeden cayabilirsiniz.

## Nasıl Kullanılır?
- "Siparişlerim" sayfasından iade talebi oluşturun veya satıcıya yazılı bildirimde bulunun.
- Bildirimden itibaren 10 gün içinde ürünü satıcıya geri gönderin.

## Cayma Hakkının Kullanılamayacağı Durumlar (özet)
- Kişiye özel üretilen ürünler.
- Çabuk bozulabilen veya son kullanma tarihi geçebilecek ürünler.
- Teslimden sonra ambalajı açılmış, sağlık ve hijyen açısından iadesi uygun olmayan ürünler.
- Elektronik ortamda anında ifa edilen hizmetler ve tüketiciye anında teslim edilen gayrimaddi mallar.
Tam liste için Mesafeli Sözleşmeler Yönetmeliği m.15 esas alınır.
T],
			['slug' => 'teslimat-kargo', 'title' => 'Teslimat ve Kargo Politikası', 'grp' => 'order', 'status' => 'published', 'review' => 'Kargo firmaları ve ücret eşiği gerçek ayarlarla eşleştirilmeli.', 'body' => self::HEADER . <<<T
## Teslimat ve Kargo
- Siparişler, her satıcının ürün sayfasında belirttiği kargoya veriliş süresi içinde kargolanır.
- Birden fazla satıcıdan alınan ürünler **ayrı paketlerle** ve ayrı kargo takip numaralarıyla gönderilebilir.
- Kargo ücreti ve ücretsiz kargo eşiği sipariş özetinde gösterilir.
- Teslimatta paketi kontrol ediniz; hasarlı paketler için kargo görevlisine tutanak tutturunuz.
- Yasal azami teslim süresi 30 gündür.
T],
			['slug' => 'sikayet-uyusmazlik', 'title' => 'Şikâyet ve Uyuşmazlık Süreci', 'grp' => 'customer', 'status' => 'published', 'review' => 'Yanıt süreleri ve başvuru kanalları doğrulanmalı.', 'body' => self::HEADER . <<<T
## Şikâyet ve Uyuşmazlık Süreci
## 1. Başvuru
Şikâyetlerinizi "Hesabım > Destek Taleplerim" bölümünden veya {{EPOSTA}} adresinden iletebilirsiniz. Talebinize bir numara verilir ve durumunu takip edebilirsiniz.

## 2. Satıcı ile Uyuşmazlık
Satıcı mağaza ile ilgili şikâyetler satıcıya iletilir; Rafımda aracı olarak süreci takip eder.

## 3. Yasal Yollar
Çözülemeyen uyuşmazlıklarda Ticaret Bakanlığınca belirlenen parasal sınırlar dahilinde Tüketici Hakem Heyetine, aşan durumlarda Tüketici Mahkemesine başvurabilirsiniz. Başvurular e-Devlet üzerinden TÜBİS ile de yapılabilir.
T],
			['slug' => 'ticari-ileti-izni', 'title' => 'Ticari Elektronik İleti Onay Metni', 'grp' => 'kvkk', 'status' => 'published', 'review' => 'İYS (İleti Yönetim Sistemi) kaydı ve kanal bazlı onay akışı doğrulanmalı.', 'body' => self::HEADER . <<<T
## Ticari Elektronik İleti Onayı
Bu onay **isteğe bağlıdır** ve varsayılan olarak verilmemiştir. Onay verirseniz {{SIRKET_UNVANI}} size kampanya, indirim ve yeni ürün duyurularını seçtiğiniz kanallardan (e-posta, SMS, telefon) gönderebilir.

- Onayınızı istediğiniz zaman, gelen iletideki ret bağlantısından veya "Hesabım" sayfasından ücretsiz olarak geri alabilirsiniz.
- Sipariş, kargo ve fatura bildirimleri ticari ileti değildir ve bu onaydan bağımsız gönderilir.
- Onay ve ret kayıtlarınız tarih ve kanal bilgisiyle saklanır; mevzuat gereği İleti Yönetim Sistemi'ne (İYS) bildirilir: {{IYS_DURUMU}}.
T],
			['slug' => 'kvkk-basvuru', 'title' => 'Kişisel Veri Başvuru Süreci', 'grp' => 'kvkk', 'status' => 'published', 'review' => 'Başvuru adresi, KEP ve kimlik doğrulama yöntemi netleştirilmeli.', 'body' => self::HEADER . <<<T
## Kişisel Veri Başvuru Süreci
KVKK m.11'deki haklarınızı kullanmak için başvurunuzu aşağıdaki yollarla iletebilirsiniz:
- Islak imzalı dilekçe ile {{ADRES}} adresine elden veya noter aracılığıyla.
- Kayıtlı elektronik posta (KEP) ile {{KEP}} adresine.
- Sistemimizde kayıtlı e-posta adresinizden {{KVKK_BASVURU_EPOSTA}} adresine.

## Başvuruda Bulunması Gerekenler
Ad, soyad, T.C. kimlik numarası (yabancılar için uyruk ve pasaport numarası), tebligata esas adres, e-posta, telefon ve talep konusu.

## Yanıt Süresi
Başvurunuz en geç **30 gün** içinde ücretsiz olarak sonuçlandırılır. İşlemin ayrıca bir maliyet gerektirmesi halinde Kurul'ca belirlenen tarifedeki ücret alınabilir.
T],
			['slug' => 'bayi-sozlesmesi', 'title' => 'Bayilik Sözleşmesi (TASLAK – PASİF)', 'grp' => 'dealer', 'status' => 'passive', 'review' => 'Bayilik ilk lansmanda pasif. Etkinleştirilmeden önce hukuki inceleme zorunlu.', 'body' => self::HEADER . <<<T
## Bayilik Sözleşmesi – TASLAK
Bu sözleşme taslaktır ve **kullanıcılara gösterilmez**. Bayilik sistemi işletme sahibinin yazılı onayıyla etkinleştirildiğinde hukuki inceleme sonrası yayına alınacaktır.

## Konu
Bayinin Rafımda'ya satıcı/müşteri yönlendirmesi ve komisyon esasları.

## Komisyon
Bayi komisyonları yalnızca hesaplama kaydıdır; ödeme koşulları ayrıca belirlenecektir.
T],
			['slug' => 'alt-bayi-sozlesmesi', 'title' => 'Alt Bayilik Sözleşmesi (TASLAK – PASİF)', 'grp' => 'dealer', 'status' => 'passive', 'review' => 'Bayilik ilk lansmanda pasif.', 'body' => self::HEADER . <<<T
## Alt Bayilik Sözleşmesi – TASLAK
Bu sözleşme taslaktır ve **kullanıcılara gösterilmez**. Ana bayi ile alt bayi arasındaki ilişki ve üst bayi payı esasları etkinleştirme öncesi belirlenecektir.
T],
		];
	}
}
