<?php

/**
 * FriSay çekirdeğindeki mevcut pazaryeri istemcilerini (core/marketplace/*Api.php) SATICI KİMLİKLERİYLE sarar.
 * Çekirdek sınıfları değiştirilmez; her satıcı kendi anahtarlarıyla kendi örneğini alır (çekirdeğin Settings tabanlı tek mağaza ayarlarına DOKUNULMAZ).
 *
 * Yetenekler:
 *  - testConnection  : salt-okunur bir çağrı ile kimlik doğrulama denemesi
 *  - updateStockPrice: LİSTELENMİŞ ürünlerin fiyat/stok güncellemesi (barkod/SKU ile)
 * Yeni ürün oluşturma (submit) BİLEREK yapılmaz: marka, kategori özellikleri, kargo firması, adres gibi doğrulanmış zorunlu alanlar
 * canlı hesap olmadan test edilemez. implemented() bu yüzden false kalır; kuyruk bu pazaryerlerine ürün yaratmış gibi davranmaz.
 */
abstract class MhCoreAdapter extends MhAbstractAdapter
{
	/** Testler için istemci üretici: [anahtar => fn(array $credentials): object] */
	public static array $factories = [];

	/** Çekirdek istemci dosyası (core/marketplace/…) */
	abstract protected function coreFile(): string;

	/** @param array<string,string> $c */
	abstract protected function makeClient(array $c): object;

	/** @return array<string,mixed>|null ham yanıt */
	abstract protected function probe(object $client): ?array;

	/** @param array<string,mixed> $item {sku, barcode, price, list_price, stock} @return list<array<string,mixed>|null> ham yanıtlar */
	abstract protected function push(object $client, array $item): array;

	/** probe için HTTP 404 "kimlik kabul edildi ama kayıt yok" anlamına gelir mi? */
	protected function notFoundMeansAuthOk(): bool
	{
		return false;
	}

	public function supportsStockPrice(): bool
	{
		return true;
	}

	/** Uzak kategori/özellik/marka okuma desteği (salt-okunur çağrılar). */
	public function supportsCatalog(): bool
	{
		return false;
	}

	/** @param array<string,string> $c @return array{ok:bool, message:string, items:list<array{id:string,name:string,path:string,leaf:bool}>} */
	public function remoteCategories(array $c): array
	{
		return ['ok' => false, 'message' => $this->title() . ' kategori listesi desteklenmiyor.', 'items' => []];
	}

	/** @param array<string,string> $c @return array{ok:bool, message:string, items:list<array<string,mixed>>} */
	public function remoteAttributes(array $c, string $remoteCategoryId): array
	{
		return ['ok' => false, 'message' => $this->title() . ' özellik listesi desteklenmiyor.', 'items' => []];
	}

	/** @param array<string,string> $c @return array{ok:bool, message:string, items:list<array{id:string,name:string}>} */
	public function remoteBrands(array $c, string $name): array
	{
		return ['ok' => false, 'message' => $this->title() . ' marka arama desteklenmiyor.', 'items' => []];
	}

	/**
	 * Toplu gönderim isteğinin sonucu (pazaryeri ürünü asenkron onaylıyorsa).
	 *
	 * @param array<string,string> $c
	 * @return array{done:bool, ok:bool, message:string, remote_id:string}
	 */
	public function batchStatus(array $c, string $batchId): array
	{
		return ['done' => true, 'ok' => false, 'message' => 'Durum sorgusu desteklenmiyor.', 'remote_id' => ''];
	}

	/** Canlı ürün gönderimi açık mı? Yönetici açana kadar KAPALI: gönderim yalnızca hazırlanır ve önizlemesi saklanır. */
	public static function liveSubmitEnabled(): bool
	{
		return class_exists('Settings') && (string) Settings::get('MARKETPLACE_HUB_LIVE_SUBMIT') === '1';
	}

	/** @param array<string,string> $c @return array{0:?object,1:string} */
	protected function client(array $c): array
	{
		foreach ($this->credentialFields() as $k => $f) {
			if ($f['required'] && trim((string) ($c[$k] ?? '')) === '') {
				return [null, $f['label'] . ' eksik.'];
			}
		}

		try {
			if (isset(self::$factories[$this->key()])) {
				return [(self::$factories[$this->key()])($c), ''];
			}
		} catch (Throwable $e) {
			return [null, $this->title() . ' istemcisi oluşturulamadı: ' . self::scrub($e->getMessage(), $c)];
		}

		$file = dirname(__DIR__, 3) . '/core/marketplace/' . $this->coreFile();

		if (!is_file($file)) {
			return [null, 'Çekirdek pazaryeri istemcisi bulunamadı.'];
		}

		try {
			require_once $file;

			return [$this->makeClient($c), ''];
		} catch (Throwable $e) {
			return [null, $this->title() . ' istemcisi oluşturulamadı: ' . self::scrub($e->getMessage(), $c)];
		}
	}

	/** @param mixed $r @return array{0:bool,1:string,2:int} [başarısız mı, mesaj, http kodu] */
	protected static function failure($r): array
	{
		if (!is_array($r)) {
			return [true, 'Pazaryerinden yanıt alınamadı.', 0];
		}

		$bad = (array_key_exists('success', $r) && $r['success'] === false) || (array_key_exists('ok', $r) && $r['ok'] === false);

		return $bad ? [true, self::clean((string) ($r['message'] ?? 'Bilinmeyen hata')), (int) ($r['httpCode'] ?? 0)] : [false, '', 0];
	}

	/** Mesajda anahtar/parola sızıntısına karşı: uzunluk sınırı, etiket temizliği. */
	protected static function clean(string $m): string
	{
		return mb_substr(trim(strip_tags($m)), 0, 200);
	}

	/** Hata metnindeki kimlik değerlerini maskeler (istisna mesajları anahtar içerebilir). @param array<string,string> $c */
	protected static function scrub(string $m, array $c): string
	{
		foreach ($c as $v) {
			if (is_string($v) && strlen($v) >= 4) {
				$m = str_replace($v, '***', $m);
			}
		}

		return self::clean($m);
	}

	public function testConnection(array $credentials): array
	{
		[$cl, $err] = $this->client($credentials);

		if (!$cl) {
			return ['success' => false, 'message' => $err];
		}

		try {
			[$bad, $msg, $code] = self::failure($this->probe($cl));
		} catch (Throwable $e) {
			return ['success' => false, 'message' => $this->title() . ' bağlantı hatası: ' . self::scrub($e->getMessage(), $credentials)];
		}

		if ($bad && $this->notFoundMeansAuthOk() && $code === 404) {
			return ['success' => true, 'message' => $this->title() . ': kimlik bilgileri reddedilmedi (test kaydı bulunamadı yanıtı alındı). Canlı doğrulama için gerçek bir ürünle stok/fiyat güncellemesi deneyin.'];
		}

		return $bad ? ['success' => false, 'message' => $this->title() . ': ' . ($code === 401 || $code === 403 ? 'kimlik bilgileri reddedildi. ' : '') . $msg] : ['success' => true, 'message' => $this->title() . ' bağlantısı başarılı.'];
	}

	/**
	 * @param array<string,string> $credentials
	 * @param list<array<string,mixed>> $items {sku, barcode, price, list_price, stock}
	 * @return array{success:bool, done:int, failed:int, errors:list<string>}
	 */
	public function updateStockPrice(array $credentials, array $items): array
	{
		$out = ['success' => false, 'done' => 0, 'failed' => 0, 'errors' => []];
		[$cl, $err] = $this->client($credentials);

		if (!$cl) {
			$out['errors'][] = $err;
			$out['failed'] = count($items);

			return $out;
		}

		foreach ($items as $it) {
			if ((float) ($it['price'] ?? 0) <= 0 || (string) ($it['sku'] ?? '') === '' && (string) ($it['barcode'] ?? '') === '') {
				$out['failed']++;
				$out['errors'][] = 'Geçersiz fiyat veya SKU/barkod yok.';

				continue;
			}

			try {
				$bad = false;
				$msg = '';

				foreach ($this->push($cl, $it) as $raw) {
					[$b, $m] = self::failure($raw);

					if ($b) {
						$bad = true;
						$msg = $m;

						break;
					}
				}
			} catch (Throwable $e) {
				$bad = true;
				$msg = self::scrub($e->getMessage(), $credentials);
			}

			if ($bad) {
				$out['failed']++;
				$out['errors'][] = (string) ($it['sku'] ?: $it['barcode']) . ': ' . $msg;
			} else {
				$out['done']++;
			}
		}

		$out['success'] = $out['failed'] === 0 && $out['done'] > 0;

		return $out;
	}
}
