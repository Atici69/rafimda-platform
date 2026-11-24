<?php

/**
 * Kimlik bilgisi (Basic Auth, ileride pazaryeri API anahtarları) şifreleme. libsodium secretbox (XSalsa20-Poly1305).
 * Anahtar Settings 'XML_HUB_SECRET' içinde tutulur (kurulumda üretilir); veritabanı sızsa bile tek başına yeterli değildir.
 * Şifre çözülemezse boş dize döner (sahte başarı yok). Düz metin hiçbir zaman günlüğe/arayüze yazılmaz.
 */
class XhCrypt
{
	private static function key(bool $create): string
	{
		$hex = (string) Settings::get('XML_HUB_SECRET');

		if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
			if (!$create) {
				return '';
			}

			$hex = bin2hex(random_bytes(32));
			Settings::set('XML_HUB_SECRET', $hex);
		}

		return (string) hex2bin($hex);
	}

	public static function available(): bool
	{
		return function_exists('sodium_crypto_secretbox');
	}

	public static function encrypt(string $plain): string
	{
		if ($plain === '' || !self::available()) {
			return '';
		}

		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		return 'v1.' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key(true)));
	}

	public static function decrypt(string $enc): string
	{
		if (strncmp($enc, 'v1.', 3) !== 0 || !self::available()) {
			return '';
		}

		$key = self::key(false);
		$raw = base64_decode(substr($enc, 3), true);

		if ($key === '' || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

		return $plain === false ? '' : $plain;
	}
}
