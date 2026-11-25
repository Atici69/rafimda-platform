<?php

/**
 * Büyük pazaryeri düzeninde (Trendyol benzeri) tam kategori ağacı.
 * Biçim: 'Ad' => [alt...] ; yaprak kategoriler düz dize. En çok 3 seviye.
 * Kurulum yalnızca EKSİK olanları ekler; mevcut kategorilere dokunmaz, hiçbir şey silmez.
 */
final class RcatTaxonomy
{
	/** @return array<string, array<int|string, mixed>> */
	public static function tree(): array
	{
		$giyimKadin = [
			'Giyim' => ['Elbise', 'Tişört', 'Gömlek', 'Bluz', 'Kot Pantolon', 'Pantolon', 'Etek', 'Şort', 'Tayt', 'Eşofman', 'Sweatshirt', 'Kazak', 'Hırka', 'Ceket', 'Mont', 'Kaban', 'Trençkot', 'Yelek', 'Tulum', 'Takım', 'Abiye', 'Tesettür Giyim', 'Büyük Beden'],
			'İç Giyim' => ['Sütyen', 'Külot', 'Pijama Takımı', 'Gecelik', 'Çorap', 'Atlet', 'Korse', 'Sabahlık'],
			'Plaj Giyim' => ['Bikini', 'Mayo', 'Haşema', 'Pareo'],
			'Ayakkabı' => ['Sneaker', 'Spor Ayakkabı', 'Topuklu Ayakkabı', 'Babet', 'Sandalet', 'Terlik', 'Bot', 'Çizme', 'Loafer', 'Ev Terliği'],
			'Çanta' => ['Omuz Çantası', 'Sırt Çantası', 'El Çantası', 'Bel Çantası', 'Cüzdan', 'Abiye Çanta', 'Plaj Çantası'],
			'Aksesuar' => ['Takı', 'Saat', 'Güneş Gözlüğü', 'Şapka', 'Şal ve Eşarp', 'Kemer', 'Eldiven', 'Bere'],
		];
		$giyimErkek = [
			'Giyim' => ['Tişört', 'Gömlek', 'Polo Yaka Tişört', 'Kot Pantolon', 'Pantolon', 'Şort', 'Eşofman', 'Sweatshirt', 'Kazak', 'Hırka', 'Ceket', 'Mont', 'Kaban', 'Yelek', 'Takım Elbise', 'Blazer', 'Büyük Beden'],
			'İç Giyim' => ['Boxer', 'Atlet', 'Çorap', 'Pijama Takımı', 'Sabahlık'],
			'Plaj Giyim' => ['Deniz Şortu', 'Plaj Havlusu'],
			'Ayakkabı' => ['Sneaker', 'Spor Ayakkabı', 'Klasik Ayakkabı', 'Bot', 'Sandalet', 'Terlik', 'Loafer', 'Ev Terliği'],
			'Çanta' => ['Sırt Çantası', 'Postacı Çantası', 'Laptop Çantası', 'Bel Çantası', 'Cüzdan', 'Valiz'],
			'Aksesuar' => ['Saat', 'Güneş Gözlüğü', 'Kemer', 'Şapka', 'Bere', 'Kravat', 'Kol Düğmesi', 'Takı'],
		];

		return [
			'Kadın' => $giyimKadin,
			'Erkek' => $giyimErkek,
			'Anne & Çocuk' => [
				'Bebek Giyim' => ['Zıbın', 'Tulum', 'Body', 'Bebek Takımı', 'Bebek Çorabı', 'Hastane Çıkışı'],
				'Kız Çocuk Giyim' => ['Elbise', 'Tişört', 'Pantolon', 'Eşofman', 'Mont', 'Pijama'],
				'Erkek Çocuk Giyim' => ['Tişört', 'Gömlek', 'Pantolon', 'Eşofman', 'Mont', 'Pijama'],
				'Çocuk Ayakkabı' => ['Spor Ayakkabı', 'Sandalet', 'Bot', 'Panduf', 'İlk Adım Ayakkabısı'],
				'Bebek Bakım' => ['Bebek Bezi', 'Islak Mendil', 'Bebek Şampuanı', 'Pişik Kremi', 'Bebek Banyo Ürünleri'],
				'Bebek Beslenme' => ['Biberon', 'Emzik', 'Mama Sandalyesi', 'Bebek Maması', 'Göğüs Pompası', 'Mama Önlüğü'],
				'Bebek Arabası ve Taşıma' => ['Bebek Arabası', 'Puset', 'Oto Koltuğu', 'Kanguru', 'Ana Kucağı'],
				'Bebek Odası' => ['Beşik', 'Bebek Karyolası', 'Park Yatak', 'Alt Değiştirme Ünitesi', 'Bebek Nevresimi'],
				'Oyuncak' => ['Eğitici Oyuncak', 'Peluş Oyuncak', 'Bebek ve Aksesuarları', 'Oyuncak Araba', 'Yapı ve Lego Oyuncakları', 'Akülü Araba', 'Bahçe Oyuncakları', 'Kutu Oyunları', 'Yapboz'],
				'Hamile Giyim' => ['Hamile Elbise', 'Hamile Pantolon', 'Emzirme Sütyeni'],
			],
			'Ev & Yaşam' => [
				'Ev Tekstili' => ['Nevresim Takımı', 'Yorgan', 'Yastık', 'Battaniye', 'Pike', 'Çarşaf', 'Havlu', 'Bornoz', 'Perde', 'Halı', 'Kilim', 'Paspas', 'Koltuk Örtüsü', 'Masa Örtüsü'],
				'Mutfak' => ['Tencere ve Tava', 'Düdüklü Tencere', 'Yemek Takımı', 'Çatal Kaşık Bıçak', 'Bardak', 'Saklama Kabı', 'Fırın Kabı', 'Çaydanlık', 'Termos', 'Mutfak Gereçleri', 'Bıçak Seti', 'Kesme Tahtası'],
				'Ev Dekorasyonu' => ['Tablo', 'Ayna', 'Vazo', 'Mum ve Mumluk', 'Dekoratif Obje', 'Çerçeve', 'Duvar Saati', 'Yapay Çiçek', 'Duvar Sticker'],
				'Aydınlatma' => ['Avize', 'Lambader', 'Masa Lambası', 'Aplik', 'Led Aydınlatma', 'Ampul'],
				'Banyo' => ['Banyo Seti', 'Duş Perdesi', 'Banyo Paspası', 'Banyo Düzenleyici'],
				'Ev Düzenleme' => ['Askı', 'Kutu ve Sepet', 'Çamaşır Sepeti', 'Kıyafet Düzenleyici', 'Ütü Masası', 'Kurutmalık'],
				'Temizlik Gereçleri' => ['Mop ve Kova', 'Süpürge Fırça', 'Temizlik Bezi', 'Çöp Kovası'],
			],
			'Mobilya' => [
				'Oturma Odası' => ['Koltuk Takımı', 'Kanepe', 'Berjer', 'Sehpa', 'TV Ünitesi', 'Kitaplık', 'Puf'],
				'Yatak Odası' => ['Yatak', 'Baza', 'Karyola', 'Gardırop', 'Komodin', 'Şifonyer', 'Yatak Odası Takımı'],
				'Yemek Odası' => ['Yemek Masası', 'Sandalye', 'Konsol', 'Vitrin'],
				'Çalışma Odası' => ['Çalışma Masası', 'Ofis Koltuğu', 'Oyuncu Koltuğu', 'Dosya Dolabı'],
				'Genç ve Çocuk Odası' => ['Ranza', 'Çocuk Yatağı', 'Çocuk Masası', 'Oyuncak Dolabı'],
				'Bahçe Mobilyası' => ['Bahçe Masa Takımı', 'Şezlong', 'Salıncak', 'Hamak'],
				'Mutfak Mobilyası' => ['Mutfak Masası', 'Bar Sandalyesi', 'Mutfak Dolabı'],
			],
			'Süpermarket' => [
				'Gıda' => ['Kahvaltılık', 'Bakliyat', 'Makarna', 'Pirinç', 'Un ve Unlu Mamuller', 'Yağ', 'Konserve', 'Sos ve Baharat', 'Atıştırmalık', 'Çikolata ve Şekerleme', 'Kuruyemiş', 'Bal ve Reçel', 'Organik Ürünler'],
				'İçecek' => ['Çay', 'Kahve', 'Su', 'Maden Suyu', 'Meyve Suyu', 'Gazlı İçecek', 'Bitki Çayı'],
				'Deterjan ve Temizlik' => ['Çamaşır Deterjanı', 'Bulaşık Deterjanı', 'Yumuşatıcı', 'Yüzey Temizleyici', 'Çamaşır Suyu', 'Oda Kokusu'],
				'Kağıt Ürünleri' => ['Tuvalet Kağıdı', 'Kağıt Havlu', 'Peçete', 'Islak Mendil'],
				'Kişisel Hijyen' => ['Diş Macunu', 'Diş Fırçası', 'Sabun', 'Duş Jeli', 'Şampuan', 'Deodorant', 'Hijyenik Ped', 'Tıraş Ürünleri'],
				'Vitamin ve Takviye' => ['Vitamin', 'Mineral', 'Balık Yağı', 'Probiyotik', 'Sporcu Gıdası'],
			],
			'Kozmetik & Kişisel Bakım' => [
				'Makyaj' => ['Fondöten', 'Kapatıcı', 'Pudra', 'Allık', 'Rimel', 'Göz Kalemi', 'Far', 'Ruj', 'Dudak Parlatıcısı', 'Oje', 'Makyaj Fırçası', 'Makyaj Seti'],
				'Cilt Bakımı' => ['Yüz Kremi', 'Nemlendirici', 'Serum', 'Yüz Temizleyici', 'Tonik', 'Yüz Maskesi', 'Göz Kremi', 'Güneş Kremi', 'Peeling'],
				'Saç Bakımı' => ['Şampuan', 'Saç Kremi', 'Saç Maskesi', 'Saç Serumu', 'Saç Boyası', 'Saç Şekillendirici'],
				'Parfüm ve Deodorant' => ['Kadın Parfüm', 'Erkek Parfüm', 'Unisex Parfüm', 'Deodorant', 'Vücut Spreyi'],
				'Ağız Bakımı' => ['Diş Macunu', 'Diş Fırçası', 'Elektrikli Diş Fırçası', 'Ağız Gargarası'],
				'Erkek Bakım' => ['Tıraş Köpüğü', 'Tıraş Losyonu', 'Sakal Bakımı', 'Tıraş Bıçağı'],
				'Kişisel Bakım Aletleri' => ['Saç Kurutma Makinesi', 'Saç Düzleştirici', 'Saç Maşası', 'Tıraş Makinesi', 'Epilatör', 'Tartı'],
				'Vücut Bakımı' => ['Vücut Losyonu', 'El Kremi', 'Duş Jeli', 'Vücut Peelingi'],
			],
			'Ayakkabı & Çanta' => [
				'Kadın Ayakkabı' => ['Sneaker', 'Topuklu Ayakkabı', 'Babet', 'Sandalet', 'Bot', 'Çizme', 'Terlik'],
				'Erkek Ayakkabı' => ['Sneaker', 'Klasik Ayakkabı', 'Bot', 'Sandalet', 'Terlik'],
				'Çocuk Ayakkabı' => ['Spor Ayakkabı', 'Sandalet', 'Bot'],
				'Kadın Çanta' => ['Omuz Çantası', 'Sırt Çantası', 'El Çantası', 'Cüzdan'],
				'Erkek Çanta' => ['Sırt Çantası', 'Laptop Çantası', 'Cüzdan'],
				'Valiz ve Bavul' => ['Kabin Boy Valiz', 'Orta Boy Valiz', 'Büyük Boy Valiz', 'Valiz Seti'],
			],
			'Saat & Aksesuar' => [
				'Saat' => ['Kadın Kol Saati', 'Erkek Kol Saati', 'Çocuk Saati', 'Akıllı Saat'],
				'Takı' => ['Kolye', 'Küpe', 'Bileklik', 'Yüzük', 'Halhal', 'Gümüş Takı', 'Altın Takı'],
				'Gözlük' => ['Güneş Gözlüğü', 'Optik Çerçeve', 'Mavi Işık Gözlüğü'],
				'Aksesuar' => ['Şapka', 'Bere', 'Şal', 'Kemer', 'Eldiven', 'Anahtarlık', 'Saç Aksesuarı'],
			],
			'Elektronik' => [
				'Telefon' => ['Cep Telefonu', 'Telefon Kılıfı', 'Ekran Koruyucu', 'Şarj Cihazı', 'Şarj Kablosu', 'Powerbank', 'Araç Tutucu', 'Telefon Aksesuarları'],
				'Bilgisayar' => ['Dizüstü Bilgisayar', 'Masaüstü Bilgisayar', 'Tablet', 'Monitör', 'Klavye', 'Mouse', 'Yazıcı', 'Harici Disk', 'USB Bellek', 'Hafıza Kartı', 'Bilgisayar Bileşenleri', 'Modem ve Ağ Ürünleri', 'Laptop Çantası', 'Tablet Kılıfı', 'Kablo ve Dönüştürücü'],
				'TV ve Görüntü' => ['Televizyon', 'Projeksiyon', 'Uydu Alıcısı', 'TV Askı Aparatı', 'Medya Oynatıcı'],
				'Ses Sistemleri' => ['Kulaklık', 'Bluetooth Kulaklık', 'Bluetooth Hoparlör', 'Hoparlör', 'Soundbar', 'Mikrofon', 'Ev Sinema Sistemi'],
				'Giyilebilir Teknoloji' => ['Akıllı Saat', 'Akıllı Bileklik'],
				'Beyaz Eşya' => ['Buzdolabı', 'Çamaşır Makinesi', 'Bulaşık Makinesi', 'Kurutma Makinesi', 'Fırın', 'Ocak', 'Derin Dondurucu', 'Ankastre Set'],
				'Elektrikli Ev Aletleri' => ['Süpürge', 'Robot Süpürge', 'Dikey Süpürge', 'Ütü', 'Buharlı Ütü', 'Klima', 'Isıtıcı', 'Vantilatör', 'Hava Temizleyici', 'Su Arıtma'],
				'Küçük Mutfak Aletleri' => ['Kahve Makinesi', 'Çay Makinesi', 'Su Isıtıcı', 'Airfryer', 'Blender', 'Mikser', 'Mutfak Robotu', 'Tost Makinesi', 'Ekmek Kızartma Makinesi', 'Doğrayıcı', 'Mikrodalga Fırın', 'Mini Fırın'],
				'Foto ve Kamera' => ['Fotoğraf Makinesi', 'Aksiyon Kamerası', 'Güvenlik Kamerası', 'Drone', 'Tripod', 'Objektif'],
				'Oyun ve Oyun Konsolları' => ['Oyun Konsolu', 'Oyun', 'Oyun Kolu', 'Oyuncu Kulaklığı', 'Oyuncu Klavyesi', 'Oyuncu Mouse'],
				'Akıllı Ev' => ['Akıllı Priz', 'Akıllı Ampul', 'Akıllı Kilit'],
			],
			'Spor & Outdoor' => [
				'Spor Giyim' => ['Spor Tişört', 'Spor Tayt', 'Eşofman Takımı', 'Spor Sütyeni', 'Spor Şort', 'Yağmurluk'],
				'Spor Ayakkabı' => ['Koşu Ayakkabısı', 'Yürüyüş Ayakkabısı', 'Futbol Ayakkabısı', 'Basketbol Ayakkabısı', 'Outdoor Ayakkabı'],
				'Fitness ve Kondisyon' => ['Koşu Bandı', 'Kondisyon Bisikleti', 'Dambıl', 'Ağırlık Seti', 'Pilates Topu', 'Yoga Matı', 'Direnç Bandı'],
				'Kamp ve Outdoor' => ['Çadır', 'Uyku Tulumu', 'Kamp Sandalyesi', 'Kamp Ocağı', 'Termos', 'Fener', 'Outdoor Çanta'],
				'Bisiklet ve Scooter' => ['Bisiklet', 'Elektrikli Scooter', 'Kask', 'Bisiklet Aksesuarları'],
				'Takım Sporları' => ['Futbol Topu', 'Basketbol Topu', 'Voleybol Topu', 'Forma'],
				'Su Sporları' => ['Yüzme Gözlüğü', 'Mayo', 'Bone', 'Şişme Ürünler'],
				'Avcılık ve Balıkçılık' => ['Olta Takımı', 'Olta Makinesi', 'Balıkçılık Aksesuarları'],
			],
			'Kitap, Kırtasiye & Hobi' => [
				'Kitap' => ['Roman', 'Çocuk Kitapları', 'Eğitim ve Sınav Kitapları', 'Kişisel Gelişim', 'Tarih', 'Din ve Mitoloji', 'Bilim', 'Çizgi Roman', 'Yabancı Dil Kitapları', 'Felsefe', 'Biyografi', 'Şiir'],
				'Kırtasiye' => ['Defter', 'Ajanda', 'Kalem', 'Kalem Kutusu', 'Okul Çantası', 'Boya ve Resim Malzemeleri', 'Dosya ve Klasör', 'Yapıştırıcı'],
				'Ofis Malzemeleri' => ['Fotokopi Kağıdı', 'Zımba', 'Etiket', 'Hesap Makinesi'],
				'Hobi' => ['Puzzle', 'Maket', 'Örgü ve Dikiş', 'Takı Tasarım', 'Koleksiyon'],
				'Müzik Aletleri' => ['Gitar', 'Klavye ve Piyano', 'Bağlama', 'Davul', 'Keman', 'Müzik Aleti Aksesuarları'],
				'Parti Malzemeleri' => ['Balon', 'Doğum Günü Süsleri', 'Parti Seti'],
			],
			'Oto, Bahçe & Yapı Market' => [
				'Oto Aksesuar' => ['Oto Paspas', 'Oto Koltuk Kılıfı', 'Araç Kamerası', 'Araç İçi Telefon Tutucu', 'Oto Kokusu', 'Araç Şarj Cihazı'],
				'Oto Bakım' => ['Motor Yağı', 'Oto Temizlik Ürünleri', 'Oto Lastik', 'Akü', 'Silecek'],
				'Motosiklet' => ['Motosiklet Kaskı', 'Motosiklet Aksesuarları'],
				'Bahçe' => ['Çim Biçme Makinesi', 'Bahçe Hortumu', 'Saksı', 'Tohum ve Fide', 'Toprak ve Gübre', 'Bahçe Aletleri', 'Mangal', 'Barbekü'],
				'Yapı Market' => ['Elektrikli El Aletleri', 'Matkap', 'El Aletleri', 'Takım Çantası', 'Hırdavat', 'Boya', 'Elektrik Malzemeleri', 'Banyo Armatürü', 'Mutfak Armatürü', 'İş Güvenliği'],
			],
			'Pet Shop' => [
				'Kedi' => ['Kedi Maması', 'Kedi Kumu', 'Kedi Oyuncağı', 'Kedi Tırmalama', 'Kedi Yatağı', 'Kedi Ödül Maması'],
				'Köpek' => ['Köpek Maması', 'Köpek Tasması', 'Köpek Oyuncağı', 'Köpek Yatağı', 'Köpek Ödül Maması'],
				'Kuş' => ['Kuş Yemi', 'Kuş Kafesi'],
				'Akvaryum' => ['Akvaryum', 'Balık Yemi', 'Akvaryum Aksesuarları'],
				'Evcil Hayvan Bakım' => ['Taşıma Çantası', 'Mama ve Su Kabı', 'Evcil Hayvan Şampuanı'],
			],
			'Sağlık & Medikal' => [
				'Medikal Cihazlar' => ['Tansiyon Aleti', 'Ateş Ölçer', 'Şeker Ölçüm Cihazı', 'Nebulizatör'],
				'Ortopedik Ürünler' => ['Ortopedik Yastık', 'Destek ve Korse', 'Ortopedik Tabanlık'],
				'Cinsel Sağlık' => ['Prezervatif', 'Kayganlaştırıcı Jel'],
				'İlk Yardım' => ['İlk Yardım Çantası', 'Yara Bandı', 'Maske', 'Dezenfektan'],
			],
		];
	}

	/**
	 * Ağacı düz yol listesine çevirir. @return array<int, array{path: string[], depth:int}>
	 */
	public static function paths(): array
	{
		$out = [];
		$walk = static function (array $node, array $prefix) use (&$walk, &$out): void {
			foreach ($node as $k => $v) {
				$name = is_int($k) ? (string) $v : (string) $k;
				$path = array_merge($prefix, [$name]);
				$out[] = ['path' => $path, 'depth' => count($path)];

				if (!is_int($k) && is_array($v)) {
					$walk($v, $path);
				}
			}
		};
		$walk(self::tree(), []);

		return $out;
	}
}
