<?php

/** Trendyol bağdaştırıcısı — YALNIZCA kimlik alanı tanımı. Gerçek API entegrasyonu henüz yok (implemented() = false). */
class MhAdapterTrendyol extends MhAbstractAdapter
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
}
