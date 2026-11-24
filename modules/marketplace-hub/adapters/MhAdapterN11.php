<?php

/** N11 bağdaştırıcısı — YALNIZCA kimlik alanı tanımı. Gerçek API entegrasyonu henüz yok (implemented() = false). */
class MhAdapterN11 extends MhAbstractAdapter
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
}
