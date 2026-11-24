<?php

/** Sosyal medya OAuth token şifreleme (libsodium secretbox). Anahtar: RAFIMDA_SOCIAL_SECRET. Düz metin loglanmaz/gösterilmez. */
final class RsoCrypt
{
	private static function key(bool $create): string
	{
		$hex = (string) Settings::get('RAFIMDA_SOCIAL_SECRET');

		if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
			if (!$create) {
				return '';
			}

			$hex = bin2hex(random_bytes(32));
			Settings::set('RAFIMDA_SOCIAL_SECRET', $hex);
		}

		return (string) hex2bin($hex);
	}

	public static function encrypt(string $plain): string
	{
		if ($plain === '' || !function_exists('sodium_crypto_secretbox')) {
			return '';
		}

		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		return 'v1.' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key(true)));
	}

	public static function decrypt(string $enc): string
	{
		$key = self::key(false);
		$raw = strncmp($enc, 'v1.', 3) === 0 ? base64_decode(substr($enc, 3), true) : false;

		if ($key === '' || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			return '';
		}

		$p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

		return $p === false ? '' : $p;
	}
}
