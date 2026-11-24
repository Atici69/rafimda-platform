<?php

/** Ozon bağdaştırıcısı — YALNIZCA kimlik alanı tanımı. Gerçek API entegrasyonu henüz yok (implemented() = false). */
class MhAdapterOzon extends MhAbstractAdapter
{
	public function key(): string
	{
		return 'ozon';
	}

	public function title(): string
	{
		return 'Ozon';
	}

	public function credentialFields(): array
	{
		return ['client_id' => self::f('Client-Id', false, true, 60), 'api_key' => self::f('Api-Key', true)];
	}
}
