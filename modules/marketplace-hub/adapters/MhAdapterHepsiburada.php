<?php

/** Hepsiburada bağdaştırıcısı — YALNIZCA kimlik alanı tanımı. Gerçek API entegrasyonu henüz yok (implemented() = false). */
class MhAdapterHepsiburada extends MhAbstractAdapter
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
}
