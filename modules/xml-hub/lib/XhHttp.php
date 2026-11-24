<?php

/**
 * Güvenli HTTP alıcı: her sıçrama (yönlendirme dahil) XhUrlGuard'dan geçer, bağlantı doğrulanan IP'ye sabitlenir,
 * boyut ve süre sınırlıdır, gövde diske/belleğe akıtılır (sınır aşılınca kesilir; gzip bombasına karşı çözülmüş boyut sayılır).
 */
class XhHttp
{
	public const MAX_BYTES = 52428800; // 50 MB (XML)
	public const MAX_REDIRECTS = 3;
	public const TIMEOUT = 25;
	public const CONNECT_TIMEOUT = 6;

	/** Testlerde değiştirilebilir: callable(array $req): array{status:int, location:string, error:string} ; gövdeyi $req['sink']($chunk) ile yazar */
	public static $transport = null;

	/**
	 * @param callable(string):bool $sink parça alır, false dönerse akış kesilir
	 * @return array{ok:bool, error:string, status:int, final_url:string}
	 */
	private static function run(string $url, int $maxBytes, int $timeout, callable $sink, ?callable $onHop = null, ?array $auth = null): array
	{
		$current = $url;
		$origin = null;

		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
			$g = XhUrlGuard::validate($current);

			if (!$g['ok']) {
				return ['ok' => false, 'error' => ($hop > 0 ? 'Yönlendirme engellendi: ' : '') . $g['error'], 'status' => 0, 'final_url' => $current];
			}

			$sameOrigin = false;

			if ($auth !== null) {
				$o = $g['scheme'] . '://' . $g['host'] . ':' . $g['port'];
				$origin = $origin ?? $o;

				if ($hop === 0 && $g['scheme'] !== 'https') {
					return ['ok' => false, 'error' => 'Kimlik doğrulamalı bağlantı yalnızca HTTPS adresiyle kullanılabilir.', 'status' => 0, 'final_url' => $current];
				}

				$sameOrigin = $o === $origin; // kimlik bilgisi başka sunucuya yönlendirmede ASLA gönderilmez
			}

			if ($onHop !== null) {
				$onHop(); // her sıçramada önceki (yönlendirme) gövdesini at
			}

			$bytes = 0;
			$tooBig = false;
			$guardedSink = static function (string $chunk) use (&$bytes, &$tooBig, $maxBytes, $sink): bool {
				$bytes += strlen($chunk);

				if ($bytes > $maxBytes) {
					$tooBig = true;

					return false;
				}

				return $sink($chunk);
			};

			$req = ['url' => $current, 'host' => $g['host'], 'port' => $g['port'], 'ip' => $g['ip'], 'scheme' => $g['scheme'], 'timeout' => $timeout, 'max_bytes' => $maxBytes, 'sink' => $guardedSink, 'auth' => $sameOrigin ? $auth : null];
			$r = self::$transport !== null ? (self::$transport)($req) : self::curl($req);

			if ($tooBig) {
				return ['ok' => false, 'error' => 'Yanıt boyut sınırını (' . (int) ($maxBytes / 1048576) . ' MB) aştı.', 'status' => (int) $r['status'], 'final_url' => $current];
			}

			if ($r['error'] !== '') {
				return ['ok' => false, 'error' => $r['error'], 'status' => (int) $r['status'], 'final_url' => $current];
			}

			if (in_array((int) $r['status'], [301, 302, 303, 307, 308], true) && $r['location'] !== '') {
				$current = self::absolute($current, $r['location']);

				continue;
			}

			if ((int) $r['status'] === 401) {
				return ['ok' => false, 'error' => $auth !== null ? 'Kullanıcı adı/şifre reddedildi (HTTP 401).' : 'Sunucu kimlik doğrulaması istiyor (HTTP 401); bağlantıya kullanıcı adı ve şifre ekleyin.', 'status' => 401, 'final_url' => $current];
			}

			if ((int) $r['status'] < 200 || (int) $r['status'] >= 300) {
				return ['ok' => false, 'error' => 'Sunucu HTTP ' . (int) $r['status'] . ' döndürdü.', 'status' => (int) $r['status'], 'final_url' => $current];
			}

			return ['ok' => true, 'error' => '', 'status' => (int) $r['status'], 'final_url' => $current];
		}

		return ['ok' => false, 'error' => 'Çok fazla yönlendirme (en fazla ' . self::MAX_REDIRECTS . ').', 'status' => 0, 'final_url' => $current];
	}

	public static function absolute(string $base, string $loc): string
	{
		if (preg_match('#^https?://#i', $loc)) {
			return $loc;
		}

		$p = parse_url($base);
		$origin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');

		if (strpos($loc, '//') === 0) {
			return ($p['scheme'] ?? 'http') . ':' . $loc;
		}

		if ($loc !== '' && $loc[0] === '/') {
			return $origin . $loc;
		}

		return $origin . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $loc;
	}

	/** @return array{status:int, location:string, error:string} */
	private static function curl(array $req): array
	{
		if (!function_exists('curl_init')) {
			return ['status' => 0, 'location' => '', 'error' => 'Sunucuda cURL eklentisi yok.'];
		}

		$location = '';
		$ch = curl_init($req['url']);
		curl_setopt_array($ch, [
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_RESOLVE => [$req['host'] . ':' . $req['port'] . ':' . (strpos($req['ip'], ':') !== false ? '[' . $req['ip'] . ']' : $req['ip'])],
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => $req['timeout'],
			CURLOPT_ENCODING => '',
			CURLOPT_USERAGENT => 'RafimdaXmlHub/1.0',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml, image/*, */*;q=0.5'],
			CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$location, $req): int {
				if (stripos($h, 'location:') === 0) {
					$location = trim(substr($h, 9));
				}

				if (stripos($h, 'content-length:') === 0 && (int) trim(substr($h, 15)) > $req['max_bytes']) {
					return 0; // baştan reddet
				}

				return strlen($h);
			},
			CURLOPT_WRITEFUNCTION => static function ($c, string $d) use ($req): int {
				if ((int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) >= 300 && (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) < 400) {
					return strlen($d); // yönlendirme gövdesi kullanılmaz
				}

				return ($req['sink'])($d) ? strlen($d) : 0;
			},
		]);
		if (!empty($req['auth'])) {
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_USERPWD, $req['auth']['user'] . ':' . $req['auth']['pass']);
		}

		$ok = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err = $ok === false ? curl_error($ch) : '';
		curl_close($ch);

		return ['status' => $status, 'location' => $location, 'error' => $err !== '' ? 'Bağlantı hatası: ' . $err : ''];
	}

	/** @return array{ok:bool, error:string, bytes:int, final_url:string} */
	public static function fetchToFile(string $url, string $dest, int $maxBytes = self::MAX_BYTES, int $timeout = self::TIMEOUT, ?array $auth = null): array
	{
		$fh = @fopen($dest, 'wb');

		if (!$fh) {
			return ['ok' => false, 'error' => 'Geçici dosya yazılamadı.', 'bytes' => 0, 'final_url' => $url];
		}

		$bytes = 0;
		$r = self::run($url, $maxBytes, $timeout, static function (string $c) use ($fh, &$bytes): bool {
			$bytes += strlen($c);

			return fwrite($fh, $c) !== false;
		}, static function () use ($fh): void {
			ftruncate($fh, 0);
			fseek($fh, 0);
		}, $auth);
		fclose($fh);

		if (!$r['ok']) {
			@unlink($dest);

			return ['ok' => false, 'error' => $r['error'], 'bytes' => 0, 'final_url' => $r['final_url']];
		}

		return ['ok' => true, 'error' => '', 'bytes' => (int) filesize($dest), 'final_url' => $r['final_url']];
	}

	/** @return array{ok:bool, error:string, body:string} */
	public static function fetchToString(string $url, int $maxBytes, int $timeout = 10): array
	{
		$buf = '';
		$r = self::run($url, $maxBytes, $timeout, static function (string $c) use (&$buf): bool {
			$buf .= $c;

			return true;
		}, static function () use (&$buf): void {
			$buf = '';
		});

		return ['ok' => $r['ok'], 'error' => $r['error'], 'body' => $r['ok'] ? $buf : ''];
	}
}
