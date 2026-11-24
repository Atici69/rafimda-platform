<?php

/** Pazarama bağdaştırıcısı — YALNIZCA kimlik alanı tanımı. Gerçek API entegrasyonu henüz yok (implemented() = false). */
class MhAdapterPazarama extends MhAbstractAdapter
{
	public function key(): string
	{
		return 'pazarama';
	}

	public function title(): string
	{
		return 'Pazarama';
	}

	public function credentialFields(): array
	{
		return ['client_id' => self::f('Client ID', false), 'client_secret' => self::f('Client Secret', true)];
	}
}
