<?php

/** İstek bağlamı: oturum açmış FriSay kullanıcısının bayi kaydını çözer. Panelin TÜM erişim kararları buradan geçer. */
class RdContext
{
	/** @var array<string,mixed>|null */
	private static ?array $cache = null;
	private static bool $resolved = false;

	public static function reset(): void
	{
		self::$cache = null;
		self::$resolved = false;
	}

	/** @return array<string,mixed>|null oturumdaki kullanıcının bayi kaydı (her durumda) */
	public static function current(): ?array
	{
		if (self::$resolved) {
			return self::$cache;
		}

		self::$resolved = true;
		self::$cache = null;

		if (!Customer::isLoggedIn()) {
			return null;
		}

		self::$cache = RdDealer::byUser(Customer::getId());

		return self::$cache;
	}

	public static function dealerId(): int
	{
		$c = self::current();

		return $c ? (int) $c['id_dealer'] : 0;
	}

	/** Panel işlevleri için: bayi onaylı ve (alt bayi ise) üst bayisi tarafından durdurulmamış olmalı. */
	public static function isOperational(): bool
	{
		$c = self::current();

		return $c !== null && RdDealer::isOperational($c);
	}

	public static function isTopLevel(): bool
	{
		$c = self::current();

		return $c !== null && (int) $c['id_parent'] === 0;
	}
}
