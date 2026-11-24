<?php

/** Bağdaştırıcı kayıt defteri. Yeni pazaryeri: adapters/MhAdapterXxx.php + buraya ekleme. Testler register() ile sahte bağdaştırıcı ekleyebilir. */
class MhRegistry
{
	public const BUILTIN = ['trendyol' => 'Trendyol', 'hepsiburada' => 'Hepsiburada', 'n11' => 'N11', 'amazon' => 'Amazon', 'pazarama' => 'Pazarama', 'ozon' => 'Ozon'];

	/** @var array<string,MhAdapter> */
	private static array $adapters = [];

	private static bool $loaded = false;

	public static function load(): void
	{
		if (self::$loaded) {
			return;
		}

		self::$loaded = true;

		foreach (self::BUILTIN as $key => $class) {
			require_once dirname(__DIR__) . '/adapters/MhAdapter' . $class . '.php';
			$cls = 'MhAdapter' . $class;
			self::$adapters[$key] = new $cls();
		}
	}

	public static function register(MhAdapter $a): void
	{
		self::load();
		self::$adapters[$a->key()] = $a;
	}

	public static function reset(): void
	{
		self::$adapters = [];
		self::$loaded = false;
	}

	public static function get(string $key): ?MhAdapter
	{
		self::load();

		return self::$adapters[$key] ?? null;
	}

	/** @return array<string,MhAdapter> */
	public static function all(): array
	{
		self::load();

		return self::$adapters;
	}
}
