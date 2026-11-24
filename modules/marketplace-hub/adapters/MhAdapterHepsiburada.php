<?php

/** Hepsiburada bağdaştırıcısı — çekirdek istemci üzerinden bağlantı testi ve stok/fiyat güncelleme (canlı hesapla doğrulanmadı). Ürün oluşturma yok: implemented() = false. */
class MhAdapterHepsiburada extends MhCoreAdapter
{
	public function key(): string
	{
		return 'hepsiburada';
	}

	public function title(): string
	{
		return 'Hepsiburada';
	}

	public function credentialFields(): array
	{
		return ['merchant_id' => self::f('Merchant ID', false, true, 60), 'username' => self::f('Kullanıcı adı', false), 'password' => self::f('Servis anahtarı / şifre', true)];
	}

	protected function coreFile(): string
	{
		return 'HepsiburadaApi.php';
	}

	protected function makeClient(array $c): object
	{
		return new \Hepsiburada\HepsiburadaApi((string) $c['merchant_id'], (string) $c['username'], (string) $c['password']);
	}

	protected function notFoundMeansAuthOk(): bool
	{
		return true;
	}

	protected function probe(object $client): ?array
	{
		return $client->getProduct('MH-BAGLANTI-TESTI');
	}

	protected function push(object $client, array $i): array
	{
		$sku = (string) ($i['sku'] ?: $i['barcode']);

		return [$client->updatePrice($sku, (float) $i['price']), $client->updateStock($sku, (int) $i['stock'])];
	}
}
