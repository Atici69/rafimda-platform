<?php

/** Amazon bağdaştırıcısı — YALNIZCA kimlik alanı tanımı. Gerçek API entegrasyonu henüz yok (implemented() = false). */
class MhAdapterAmazon extends MhAbstractAdapter
{
	public function key(): string
	{
		return 'amazon';
	}

	public function title(): string
	{
		return 'Amazon';
	}

	public function credentialFields(): array
	{
		return ['seller_id' => self::f('Seller ID', false, true, 40), 'marketplace_id' => self::f('Marketplace ID', false, true, 40), 'refresh_token' => self::f('LWA Refresh Token', true, true, 600), 'client_id' => self::f('LWA Client ID', false, true, 200), 'client_secret' => self::f('LWA Client Secret', true)];
	}
}
