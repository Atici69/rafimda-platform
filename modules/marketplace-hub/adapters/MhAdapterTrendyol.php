<?php

/** Trendyol bağdaştırıcısı — çekirdek istemci üzerinden bağlantı testi ve stok/fiyat güncelleme (canlı hesapla doğrulanmadı). Ürün oluşturma yok: implemented() = false. */
class MhAdapterTrendyol extends MhCoreAdapter
{
	public function key(): string
	{
		return 'trendyol';
	}

	public function title(): string
	{
		return 'Trendyol';
	}

	public function credentialFields(): array
	{
		return ['supplier_id' => self::f('Satıcı (Supplier) ID', false, true, 40), 'api_key' => self::f('API Key', true), 'api_secret' => self::f('API Secret', true)];
	}

	protected function coreFile(): string
	{
		return 'TrendyolApi.php';
	}

	protected function makeClient(array $c): object
	{
		return new \Trendyol\TrendyolApi((string) $c['supplier_id'], (string) $c['api_key'], (string) $c['api_secret']);
	}

	protected function probe(object $client): ?array
	{
		return $client->getProducts(1, 0);
	}

	protected function push(object $client, array $i): array
	{
		return [$client->updateStockPrice((string) ($i['barcode'] ?: $i['sku']), (float) $i['list_price'], (float) $i['price'], (int) $i['stock'], (string) $i['sku'])];
	}
}
