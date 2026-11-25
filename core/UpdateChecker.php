<?php

/**
 * Remote update channel (WordPress-style version check).
 * Fixed official channel: GitHub Releases → Rafımda'nın kendi deposu (Atici69/rafimda-platform).
 * Yukarı akış FriSay deposu (bera65/frisay) KASITLI olarak kullanılmaz: aksi halde FriSay'ın daha yüksek
 * sürüm numaralı paketi (ör. 3.1.0) Rafımda dosyalarının üzerine yazılır ve dosya/şema sürümleri ayrışır.
 */
class UpdateChecker
{
	public const GITHUB_REPO = 'Atici69/rafimda-platform';

	public const KEY_LAST_CHECK = 'FS_UPDATE_LAST_CHECK';
	public const KEY_LAST_OFFER = 'FS_UPDATE_LAST_OFFER';
	public const KEY_LAST_FAIL = 'FS_UPDATE_LAST_FAIL';
	public const KEY_LAST_ERROR = 'FS_UPDATE_LAST_ERROR';

	/** Auto re-check while browsing admin (seconds). */
	public const AUTO_CHECK_TTL = 43200;

	/** Başarısız otomatik kontrolden sonra yeniden denemeden önce beklenecek süre (saniye). */
	public const FAIL_BACKOFF = 1800;

	/** HTTP zaman aşımları (saniye); arka plan kontrolünde kısaltılır ki yönetim sayfası takılmasın. */
	private static int $connectTimeout = 15;
	private static int $timeout = 60;

	public static function ensureDefaults(): void
	{
	}

	/**
	 * Throttled check for admin menu badge.
	 *
	 * @return array{update_available:bool,version:?string}
	 */
	public static function maybeRefresh(): array
	{
		$current = Upgrade::getCodeVersion();
		$last = trim((string) Settings::get(self::KEY_LAST_CHECK));
		$stale = true;

		if ($last !== '') {
			$ts = strtotime($last);

			if ($ts !== false && (time() - $ts) < self::AUTO_CHECK_TTL) {
				$stale = false;
			}
		}

		// Başarısız kontrol sonrası geri çekilme: GitHub'a ulaşılamıyorsa her yönetim sayfası yüklemesinde
		// 75 sn'ye varan bekleme yaşanmaz.
		$lastFail = trim((string) Settings::get(self::KEY_LAST_FAIL));

		if ($stale && $lastFail !== '') {
			$failTs = strtotime($lastFail);

			if ($failTs !== false && (time() - $failTs) < self::FAIL_BACKOFF) {
				$stale = false;
			}
		}

		if ($stale) {
			self::$connectTimeout = 4;
			self::$timeout = 8;

			try {
				self::check(true);
			} finally {
				self::$connectTimeout = 15;
				self::$timeout = 60;
			}
		}

		$offer = self::getCachedOffer();
		$available = $offer && version_compare($offer['version'], $current, '>');

		return [
			'update_available' => (bool) $available,
			'version' => $available ? (string) $offer['version'] : null,
		];
	}

	/**
	 * @return array{
	 *   success:bool,
	 *   message:string,
	 *   offer:?array{
	 *     version:string,
	 *     download_url:string,
	 *     changelog_url:string,
	 *     min_php:string,
	 *     title:string,
	 *     source:string
	 *   },
	 *   update_available:bool,
	 *   current:string
	 * }
	 */
	public static function check(bool $force = true): array
	{
		$current = Upgrade::getCodeVersion();

		if (!$force) {
			$cached = self::getCachedOffer();

			if ($cached !== null) {
				$available = version_compare($cached['version'], $current, '>');

				return [
					'success' => true,
					'message' => $available
						? 'Yeni sürüm bulundu: ' . $cached['version']
						: 'Sistem güncel (' . $current . ').',
					'offer' => $cached,
					'update_available' => $available,
					'current' => $current,
				];
			}
		}

		$result = self::fetchGithubLatest(self::GITHUB_REPO);

		if (empty($result['success']) || empty($result['offer'])) {
			$failMsg = (string) ($result['message'] ?? 'Sürüm kontrolü başarısız');
			Settings::set(self::KEY_LAST_FAIL, date('c'));
			Settings::set(self::KEY_LAST_ERROR, mb_substr($failMsg, 0, 300));

			return [
				'success' => false,
				'message' => $failMsg,
				'offer' => null,
				'update_available' => false,
				'current' => $current,
			];
		}

		/** @var array{version:string,download_url:string,changelog_url:string,min_php:string,title:string,source:string} $offer */
		$offer = $result['offer'];
		self::storeCachedOffer($offer);

		$available = version_compare($offer['version'], $current, '>');

		return [
			'success' => true,
			'message' => $available
				? 'Yeni sürüm bulundu: ' . $offer['version']
				: 'Sistem güncel (' . $current . ').',
			'offer' => $offer,
			'update_available' => $available,
			'current' => $current,
		];
	}

	/**
	 * @return array{version:string,download_url:string,changelog_url:string,min_php:string,title:string,source:string}|null
	 */
	public static function getCachedOffer(): ?array
	{
		$raw = trim((string) Settings::get(self::KEY_LAST_OFFER));

		if ($raw === '') {
			return null;
		}

		$data = json_decode($raw, true);

		if (!is_array($data)) {
			return null;
		}

		$offer = self::normalizeOffer($data, (string) ($data['source'] ?? 'cache'));

		return $offer;
	}

	/**
	 * @param array{version:string,download_url:string,changelog_url:string,min_php:string,title:string,source:string} $offer
	 */
	private static function storeCachedOffer(array $offer): void
	{
		Settings::set(self::KEY_LAST_CHECK, date('c'));
		Settings::set(self::KEY_LAST_FAIL, '');
		Settings::set(self::KEY_LAST_ERROR, '');
		Settings::set(self::KEY_LAST_OFFER, (string) json_encode($offer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @return array{success:bool,message:string,offer?:array}
	 */
	private static function fetchGithubLatest(string $repo): array
	{
		$repo = trim($repo, '/');
		$url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$response = self::httpGetDetailed($url, [
			'Accept: application/vnd.github+json',
			'User-Agent: Rafimda-UpdateChecker',
		]);

		if ($response['body'] === null) {
			$code = (int) ($response['code'] ?? 0);
			$err = (string) ($response['error'] ?? '');

			if ($code === 404) {
				return [
					'success' => false,
					'message' => 'GitHub’da henüz yayınlanmış release yok. https://github.com/' . $repo . '/releases adresinden tag + ZIP yayınlayın.',
				];
			}

			if ($err !== '' && (stripos($err, 'SSL') !== false || stripos($err, 'certificate') !== false)) {
				return [
					'success' => false,
					'message' => 'SSL sertifika hatası (WAMP). core/cacert.pem dosyasının mevcut olduğundan emin olun. Detay: ' . $err,
				];
			}

			return [
				'success' => false,
				'message' => 'GitHub Releases API yanıt vermedi'
					. ($code > 0 ? ' (HTTP ' . $code . ')' : '')
					. ($err !== '' ? ': ' . $err : ''),
			];
		}

		$data = json_decode($response['body'], true);

		if (!is_array($data) || (isset($data['message']) && empty($data['tag_name']))) {
			$msg = is_array($data) ? (string) ($data['message'] ?? 'Bilinmeyen hata') : 'Geçersiz yanıt';

			if (stripos($msg, 'Not Found') !== false) {
				return [
					'success' => false,
					'message' => 'GitHub’da henüz yayınlanmış release yok. https://github.com/' . $repo . '/releases adresinden tag + ZIP yayınlayın.',
				];
			}

			return ['success' => false, 'message' => 'GitHub: ' . $msg];
		}

		$version = self::normalizeVersionTag((string) ($data['tag_name'] ?? ''));

		if ($version === '' || !Upgrade::isValidVersion($version)) {
			return ['success' => false, 'message' => 'Release etiketi semver değil: ' . (string) ($data['tag_name'] ?? '')];
		}

		$downloadUrl = self::pickGithubAssetUrl($data, $version);
		$sha256 = self::pickGithubAssetDigest($data, $downloadUrl);

		if ($downloadUrl === '' || !self::isHttpsUrl($downloadUrl)) {
			return ['success' => false, 'message' => 'İndirilebilir HTTPS ZIP bulunamadı (rafimda-x.y.z.zip asset ekleyin; tools/build-release.php üretir)'];
		}

		$offer = [
			'version' => $version,
			'download_url' => $downloadUrl,
			'changelog_url' => (string) ($data['html_url'] ?? ''),
			'min_php' => '7.4',
			'title' => (string) ($data['name'] ?? ('Rafımda ' . $version)),
			'source' => 'github:' . $repo,
		];

		if ($sha256 !== '') {
			$offer['sha256'] = $sha256;
		}

		return ['success' => true, 'message' => 'OK', 'offer' => $offer];
	}

	/**
	 * GitHub varlık özeti ("digest": "sha256:…") varsa döner; indirilen paket bununla doğrulanır.
	 *
	 * @param array<string, mixed> $release
	 */
	private static function pickGithubAssetDigest(array $release, string $url): string
	{
		foreach (($release['assets'] ?? []) as $asset) {
			if (is_array($asset) && (string) ($asset['browser_download_url'] ?? '') === $url) {
				$d = strtolower(trim((string) ($asset['digest'] ?? '')));

				if (preg_match('/^sha256:([a-f0-9]{64})$/', $d, $m)) {
					return $m[1];
				}
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private static function pickGithubAssetUrl(array $release, string $version): string
	{
		$assets = $release['assets'] ?? [];

		if (is_array($assets)) {
			$preferred = [];
			$fallback = [];

			foreach ($assets as $asset) {
				if (!is_array($asset)) {
					continue;
				}

				$name = strtolower((string) ($asset['name'] ?? ''));
				$url = (string) ($asset['browser_download_url'] ?? '');

				if ($url === '' || substr($name, -4) !== '.zip') {
					continue;
				}

				$branded = (
					strpos($name, 'frisay') !== false
					|| preg_match('/(^|[^a-z])fs[-_]/', $name)
					|| strpos($name, 'fshop') !== false
					|| strpos($name, $version) !== false
				);

				if ($branded) {
					$preferred[] = $url;
				} else {
					$fallback[] = $url;
				}
			}

			if ($preferred !== []) {
				return $preferred[0];
			}

			if ($fallback !== []) {
				return $fallback[0];
			}
		}

		// Source zipball (repo archive) — works even without uploaded assets.
		return (string) ($release['zipball_url'] ?? '');
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{version:string,download_url:string,changelog_url:string,min_php:string,title:string,source:string}|null
	 */
	private static function normalizeOffer(array $data, string $source): ?array
	{
		$version = self::normalizeVersionTag((string) ($data['version'] ?? ''));
		$download = trim((string) ($data['download_url'] ?? ''));

		if ($version === '' || !Upgrade::isValidVersion($version) || $download === '') {
			return null;
		}

		if (!self::isHttpsUrl($download)) {
			return null;
		}

		$offer = [
			'version' => $version,
			'download_url' => $download,
			'changelog_url' => trim((string) ($data['changelog_url'] ?? '')),
			'min_php' => trim((string) ($data['min_php'] ?? '7.4')) ?: '7.4',
			'title' => trim((string) ($data['title'] ?? ('Rafımda ' . $version))) ?: ('Rafımda ' . $version),
			'source' => $source,
		];
		$sha = strtolower(trim((string) ($data['sha256'] ?? '')));

		if (preg_match('/^[a-f0-9]{64}$/', $sha)) {
			$offer['sha256'] = $sha;
		}

		return $offer;
	}

	public static function normalizeVersionTag(string $tag): string
	{
		$tag = trim($tag);

		if ($tag === '') {
			return '';
		}

		// Accept: 2.5.6, v2.5.6, V.2.5.6, frisay-2.5.6, fs_2.5.6, …
		if (preg_match('/(\d+\.\d+\.\d+)/', $tag, $m)) {
			return $m[1];
		}

		return $tag;
	}

	public static function isHttpsUrl(string $url): bool
	{
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}

		return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
	}

	public static function caBundlePath(): string
	{
		$path = __DIR__ . '/cacert.pem';

		return is_file($path) ? $path : '';
	}

	/**
	 * @param list<string> $headers
	 * @return array{body:?string,code:int,error:string}
	 */
	public static function httpGetDetailed(string $url, array $headers = []): array
	{
		if (!self::isHttpsUrl($url)) {
			return ['body' => null, 'code' => 0, 'error' => 'URL HTTPS değil'];
		}

		$defaultHeaders = $headers !== [] ? $headers : ['User-Agent: Rafimda-UpdateChecker'];
		$ca = self::caBundlePath();

		if (function_exists('curl_init')) {
			$ch = curl_init($url);

			if ($ch === false) {
				return ['body' => null, 'code' => 0, 'error' => 'curl_init başarısız'];
			}

			$opts = [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_CONNECTTIMEOUT => self::$connectTimeout,
				CURLOPT_TIMEOUT => self::$timeout,
				CURLOPT_HTTPHEADER => $defaultHeaders,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			];

			if ($ca !== '') {
				$opts[CURLOPT_CAINFO] = $ca;
			}

			curl_setopt_array($ch, $opts);
			$body = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err = (string) curl_error($ch);
			curl_close($ch);

			if ($body === false) {
				return ['body' => null, 'code' => $code, 'error' => $err !== '' ? $err : 'curl_exec failed'];
			}

			if ($code < 200 || $code >= 300) {
				return ['body' => null, 'code' => $code, 'error' => $err !== '' ? $err : ('HTTP ' . $code)];
			}

			return ['body' => (string) $body, 'code' => $code, 'error' => ''];
		}

		if (!ini_get('allow_url_fopen')) {
			return ['body' => null, 'code' => 0, 'error' => 'curl yok ve allow_url_fopen kapalı'];
		}

		$ssl = [
			'verify_peer' => true,
			'verify_peer_name' => true,
		];

		if ($ca !== '') {
			$ssl['cafile'] = $ca;
		}

		$ctx = stream_context_create([
			'http' => [
				'timeout' => self::$timeout,
				'header' => implode("\r\n", $defaultHeaders),
				'ignore_errors' => true,
			],
			'ssl' => $ssl,
		]);

		$body = @file_get_contents($url, false, $ctx);
		$code = 0;

		if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
			$code = (int) $m[1];
		}

		if ($body === false) {
			return ['body' => null, 'code' => $code, 'error' => 'file_get_contents failed'];
		}

		if ($code > 0 && ($code < 200 || $code >= 300)) {
			return ['body' => null, 'code' => $code, 'error' => 'HTTP ' . $code];
		}

		return ['body' => (string) $body, 'code' => $code ?: 200, 'error' => ''];
	}

	/**
	 * @param list<string> $headers
	 */
	public static function httpGet(string $url, array $headers = []): ?string
	{
		$result = self::httpGetDetailed($url, $headers);

		if ($result['body'] === null) {
			return null;
		}

		$code = (int) ($result['code'] ?? 0);

		if ($code > 0 && ($code < 200 || $code >= 300)) {
			return null;
		}

		return $result['body'];
	}
}
