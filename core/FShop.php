<?php

class FShop
{
	public const VERSION = '3.0.3';
	public const NAME = 'FriSay';

	/**
	 * Güncelleme kanalı kimliği. UpdateInstaller yalnızca aynı kanalın paketini kurar;
	 * böylece yukarı akış (FriSay) paketi yanlışlıkla Rafımda dosyalarının üzerine yazılamaz.
	 */
	public const CHANNEL = 'rafimda';

	public static function version(): string
	{
		return self::VERSION;
	}

	public static function fullName(): string
	{
		return self::NAME . ' ' . self::VERSION;
	}
}
