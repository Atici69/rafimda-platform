<?php

/** N11 bağdaştırıcısı — çekirdek istemci üzerinden bağlantı testi ve stok/fiyat güncelleme (canlı hesapla doğrulanmadı). Ürün oluşturma yok: implemented() = false. */
class MhAdapterN11 extends MhCoreAdapter
{
	public function key(): string
	{
		return 'n11';
	}

	public function title(): string
	{
		return 'N11';
	}

	public function credentialFields(): array
	{
		return ['app_key' => self::f('Uygulama anahtarı (appKey)', true), 'app_secret' => self::f('Uygulama şifresi (appSecret)', true)];
	}

	protected function coreFile(): string
	{
		return 'N11Api.php';
	}

	protected function makeClient(array $c): object
	{
		return new \N11\N11Api((string) $c['app_key'], (string) $c['app_secret']);
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
		return [$client->updateStockPrice((string) ($i['sku'] ?: $i['barcode']), (float) $i['list_price'], (float) $i['price'], (int) $i['stock'])];
	}
}
