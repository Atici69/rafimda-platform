<?php

/**
 * Paket güncelleme: indir → doğrula → aç → paketi doğrula → yedekle+kopyala → migration → önbellek.
 *
 * Güvenlik özeti:
 *  - Yalnızca aynı kanalın (FShop::CHANNEL) paketi kurulur; yukarı akış/yanlış paket reddedilir.
 *  - Şema sürümü dosya sürümünden ileride ise güncelleme engellenir (önce yönetici onaylı eşitleme gerekir).
 *  - Eşzamanlı güncelleme dosya kilidiyle engellenir.
 *  - Üzerine yazılacak her dosya önce yedeklenir (backups/update-*); kopyalama sırasında hata olursa
 *    tüm değişiklik geri alınır, böylece yarım/karışık sürüm kalmaz.
 *  - Bakım modu zaman damgalıdır; süreç ortada ölse bile MAINTENANCE_TTL sonra kendiliğinden biter.
 *  - Her adım logs/update.log dosyasına yazılır.
 */
class UpdateInstaller
{
	public const KEY_MAINTENANCE = 'FS_MAINTENANCE';
	public const KEY_MAINTENANCE_SINCE = 'FS_MAINTENANCE_SINCE';

	/** Bakım modunun en fazla süresi (saniye). Süreç yarıda kesilirse site bu sürede kendiliğinden açılır. */
	public const MAINTENANCE_TTL = 900;

	/** Saklanacak en fazla güncelleme yedeği sayısı. */
	public const KEEP_BACKUPS = 3;

	/** Yalnızca testler için: kök dizin ve indirme davranışı. */
	public static ?string $rootOverride = null;

	/** @var (callable(string,string,array):bool)|null */
	public static $downloadOverride = null;

	/** @var (callable(string,string):void)|null Testlerde kopyalama sırasında hata enjekte etmek için. */
	public static $copyHook = null;

	private static string $runId = '';

	/** @return list<string> Relative path prefixes that must never be overwritten */
	public static function protectedPrefixes(): array
	{
		return [
			'config/env.php',
			'config/env0.php',
			'config/installed.lock',
			'config/debug.log',
			'.htaccess',          // yalnızca KÖK .htaccess (cPanel PHP sürüm/handler satırları burada tutulur)
			'img/',
			'cache/',
			'storage/',
			'backups/',
			'logs/',
			'tmp/',
			'.git/',
		];
	}

	public static function rootDir(): string
	{
		return self::$rootOverride !== null ? rtrim(self::$rootOverride, '/\\') : dirname(__DIR__);
	}

	public static function stagingDir(): string
	{
		return self::rootDir() . '/cache/updates';
	}

	public static function backupRoot(): string
	{
		return self::rootDir() . '/backups';
	}

	// ------------------------------------------------------------------ bakım modu

	public static function setMaintenance(bool $on): void
	{
		Settings::set(self::KEY_MAINTENANCE, $on ? '1' : '0');
		Settings::set(self::KEY_MAINTENANCE_SINCE, $on ? (string) time() : '');
	}

	/**
	 * Bakım açık VE süresi dolmamışsa true. Süresi dolmuş (yarıda kalmış güncellemeden artakalan) bayrak
	 * otomatik temizlenir; böylece mağaza sonsuza dek "Güncelleme yapılıyor" ekranında kalmaz.
	 */
	public static function isMaintenance(): bool
	{
		if (Settings::get(self::KEY_MAINTENANCE) !== '1') {
			return false;
		}

		$since = (int) Settings::get(self::KEY_MAINTENANCE_SINCE);

		// Zaman damgası yoksa (eski sürümden kalan bayrak) güvenli tarafta: süresi dolmuş say.
		if ($since <= 0 || (time() - $since) > self::MAINTENANCE_TTL) {
			Settings::set(self::KEY_MAINTENANCE, '0');
			Settings::set(self::KEY_MAINTENANCE_SINCE, '');
			self::log('Süresi dolmuş bakım modu bayrağı otomatik temizlendi');

			return false;
		}

		return true;
	}

	// ------------------------------------------------------------------ günlük

	public static function logFile(): string
	{
		return self::rootDir() . '/logs/update.log';
	}

	public static function log(string $line): void
	{
		$file = self::logFile();
		$dir = dirname($file);

		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
			return;
		}

		if (is_file($file) && (int) @filesize($file) > 524288) {
			@rename($file, $file . '.1');
		}

		$id = self::$runId !== '' ? self::$runId : '-';
		@file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] [' . $id . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
	}

	// ------------------------------------------------------------------ ana akış

	/**
	 * @param array{version:string,download_url:string,min_php?:string,sha256?:string}|null $offer
	 * @return array{success:bool,message:string,logs:list<string>}
	 */
	public static function runFullUpdate(?array $offer = null): array
	{
		@set_time_limit(0);
		@ini_set('memory_limit', '512M');

		$logs = [];
		self::$runId = 'upd_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

		if (!class_exists('ZipArchive', false)) {
			return self::fail('PHP ZipArchive eklentisi gerekli', $logs);
		}

		$state = Upgrade::getVersionState();

		if ($state['schema_ahead']) {
			return self::fail(
				'Güncelleme engellendi: veritabanı şema sürümü (' . $state['installed'] . ') dosya sürümünden (' . $state['code'] . ') yeni. '
				. 'Dosyalar geri alınmış ya da farklı bir paketle güncellenmiş olabilir. Önce yedek alın, sonra Güncelleme ekranındaki '
				. '"Şema sürümünü eşitle" işlemini yönetici onayıyla uygulayın.',
				$logs
			);
		}

		if ($offer === null) {
			$check = UpdateChecker::check(true);

			if (empty($check['success']) || empty($check['offer'])) {
				return self::fail((string) ($check['message'] ?? 'Sürüm teklifi yok'), $logs);
			}

			$offer = $check['offer'];

			if (empty($check['update_available'])) {
				return [
					'success' => true,
					'message' => 'Zaten güncel sürümdesiniz.',
					'logs' => ['Kod sürümü: ' . Upgrade::getCodeVersion()],
				];
			}
		}

		$version = (string) ($offer['version'] ?? '');
		$downloadUrl = (string) ($offer['download_url'] ?? '');
		$minPhp = (string) ($offer['min_php'] ?? '7.4');
		$expectSha = strtolower((string) ($offer['sha256'] ?? ''));

		if (!Upgrade::isValidVersion($version) || $downloadUrl === '') {
			return self::fail('Geçersiz güncelleme teklifi', $logs);
		}

		if (version_compare(PHP_VERSION, $minPhp, '<')) {
			return self::fail('Bu sürüm için PHP ' . $minPhp . '+ gerekli (şu an ' . PHP_VERSION . ')', $logs);
		}

		if (!UpdateChecker::isHttpsUrl($downloadUrl)) {
			return self::fail('İndirme URL yalnızca HTTPS olmalı', $logs);
		}

		$current = Upgrade::getCodeVersion();

		if (!version_compare($version, $current, '>')) {
			return [
				'success' => true,
				'message' => 'Teklif edilen sürüm kod sürümünden yeni değil.',
				'logs' => ['current=' . $current, 'offer=' . $version],
			];
		}

		$staging = self::stagingDir();

		if (!is_dir($staging) && !@mkdir($staging, 0755, true)) {
			return self::fail('Staging dizini oluşturulamadı: cache/updates (dizin izinlerini kontrol edin)', $logs);
		}

		$lock = self::acquireLock();

		if ($lock === null) {
			return self::fail('Başka bir güncelleme şu anda çalışıyor. Birkaç dakika sonra tekrar deneyin.', $logs);
		}

		$workDir = $staging . '/' . self::$runId;
		$zipPath = $workDir . '/package.zip';

		if (!@mkdir($workDir, 0755, true)) {
			self::releaseLock($lock);

			return self::fail('Çalışma dizini oluşturulamadı', $logs);
		}

		$phase = 'hazırlık';
		$backupDir = '';
		$manifest = ['replaced' => [], 'created' => []];
		$result = null;

		self::log('Güncelleme başladı: ' . $current . ' → ' . $version);
		self::setMaintenance(true);
		$logs[] = 'Bakım modu açıldı';

		try {
			$phase = 'indirme';
			$logs[] = 'İndiriliyor: ' . $version;

			if (!self::downloadToFile($downloadUrl, $zipPath, $logs)) {
				throw new RuntimeException('Paket indirilemedi (ağ/SSL hatası olabilir; logs/update.log dosyasına bakın)');
			}

			$phase = 'doğrulama';
			if ($expectSha !== '') {
				$actual = (string) hash_file('sha256', $zipPath);

				if (!hash_equals($expectSha, strtolower($actual))) {
					throw new RuntimeException('Paket bütünlük doğrulaması başarısız (SHA-256 uyuşmuyor); dosya bozuk veya değiştirilmiş');
				}

				$logs[] = 'SHA-256 doğrulandı';
			} else {
				$logs[] = 'Uyarı: yayın SHA-256 özeti vermedi; yalnızca ZIP yapısı ve içerik doğrulandı';
			}

			$logs[] = 'ZIP açılıyor…';
			$extractDir = $workDir . '/extracted';

			if (!@mkdir($extractDir, 0755, true)) {
				throw new RuntimeException('Extract dizini oluşturulamadı');
			}

			self::extractZip($zipPath, $extractDir);
			$packageRoot = self::resolvePackageRoot($extractDir);
			$logs[] = 'Paket kökü: ' . basename($packageRoot);

			self::validatePackage($packageRoot, $version, $logs);

			$phase = 'dosya kopyalama';
			$backupDir = self::backupRoot() . '/update-' . date('Ymd-His') . '-' . $current . '-to-' . $version;
			$logs[] = 'Dosyalar yedeklenerek kopyalanıyor (korumalı yollar atlanıyor)…';
			$copied = self::copyPackage($packageRoot, self::rootDir(), $backupDir, $manifest, $logs);
			$logs[] = 'Değişen/yeni dosya: ' . $copied . ' (yedek: ' . basename($backupDir) . ')';

			clearstatcache(true);
			$onDisk = Upgrade::readCodeVersionFromDisk();

			if ($onDisk !== $version) {
				throw new RuntimeException('Kopyalama sonrası dosya sürümü beklenenle uyuşmuyor (disk: ' . ($onDisk ?: '?') . ', beklenen: ' . $version . ')');
			}

			self::resetOpcache($manifest);
		} catch (Throwable $e) {
			$logs[] = 'Hata (' . $phase . '): ' . $e->getMessage();
			self::log('HATA [' . $phase . ']: ' . $e->getMessage());

			if ($phase === 'dosya kopyalama' || $backupDir !== '') {
				$logs[] = 'Değişiklikler geri alınıyor…';
				$rb = self::rollback($backupDir, $manifest, $logs);
				$logs[] = $rb ? 'Geri alma tamamlandı; site önceki sürümde.' : 'UYARI: geri alma eksik kaldı; yedek klasörünü kontrol edin: ' . basename($backupDir);
			}

			self::setMaintenance(false);
			self::rrmdir($workDir);
			self::releaseLock($lock);
			$logs[] = 'Bakım modu kapatıldı';

			return [
				'success' => false,
				'message' => 'Güncelleme başarısız (' . $phase . '): ' . $e->getMessage() . '. Mevcut sürüm korundu. Ayrıntı: logs/update.log',
				'logs' => $logs,
			];
		}

		// Dosyalar yeni sürümde. Bundan sonraki hatada dosyalar GERİ ALINMAZ (veritabanı kısmen ilerlemiş olabilir).
		try {
			$phase = 'migration';
			$logs[] = 'Veritabanı migration çalıştırılıyor…';
			$migrate = Upgrade::runPending();

			foreach ($migrate['logs'] as $line) {
				$logs[] = $line;
			}

			if (empty($migrate['success'])) {
				throw new RuntimeException((string) ($migrate['message'] ?? 'Migration başarısız'));
			}

			$phase = 'önbellek';
			if (class_exists('Performance', false)) {
				$clear = Performance::clearCaches();
				$logs[] = 'Önbellek temizlendi: ' . (string) ($clear['message'] ?? 'OK');
			}

			self::pruneBackups();
			$logs[] = 'Tamam: ' . $version;
			self::log('Güncelleme tamamlandı: ' . $version);
			$result = ['success' => true, 'message' => 'Güncelleme tamamlandı (' . $version . ').', 'logs' => $logs];
		} catch (Throwable $e) {
			$logs[] = 'Hata (' . $phase . '): ' . $e->getMessage();
			self::log('HATA [' . $phase . ']: ' . $e->getMessage());
			$result = [
				'success' => false,
				'message' => 'Dosyalar ' . $version . ' sürümüne güncellendi ancak "' . $phase . '" adımı tamamlanamadı: ' . $e->getMessage()
					. ' Veri silinmedi. Güncelleme ekranındaki "Veritabanı güncellemesini uygula" ile adımı yeniden deneyin. Ayrıntı: logs/update.log',
				'logs' => $logs,
			];
		} finally {
			self::setMaintenance(false);
			self::rrmdir($workDir);
			self::releaseLock($lock);
		}

		$result['logs'][] = 'Bakım modu kapatıldı';

		return $result;
	}

	/**
	 * @param list<string> $logs
	 * @return array{success:bool,message:string,logs:list<string>}
	 */
	private static function fail(string $message, array $logs): array
	{
		$logs[] = $message;
		self::log('REDDEDİLDİ: ' . $message);

		return ['success' => false, 'message' => $message, 'logs' => $logs];
	}

	// ------------------------------------------------------------------ kilit

	/** @return resource|null */
	private static function acquireLock()
	{
		$file = self::stagingDir() . '/update.lock';
		$fp = @fopen($file, 'c');

		if ($fp === false) {
			return null;
		}

		if (!flock($fp, LOCK_EX | LOCK_NB)) {
			fclose($fp);

			return null;
		}

		return $fp;
	}

	/** @param resource $fp */
	private static function releaseLock($fp): void
	{
		if (is_resource($fp)) {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
	}

	// ------------------------------------------------------------------ paket doğrulama

	/**
	 * Paket gerçekten bu ürünün, beklenen sürümde ve sözdizimi bozuk olmayan bir paketi mi?
	 *
	 * @param list<string> $logs
	 */
	private static function validatePackage(string $root, string $version, array &$logs): void
	{
		foreach (['core/FShop.php', 'core/Upgrade.php', 'core/UpdateInstaller.php', 'core/UpdateChecker.php', 'config/settings.php', 'index.php', 'upgrade/migrations'] as $must) {
			if (!file_exists($root . '/' . $must)) {
				throw new RuntimeException('Paket eksik/uyumsuz: ' . $must . ' bulunamadı');
			}
		}

		$shop = (string) file_get_contents($root . '/core/FShop.php');

		if (!preg_match("/const\\s+CHANNEL\\s*=\\s*'([a-z0-9_-]+)'/", $shop, $m) || $m[1] !== FShop::CHANNEL) {
			throw new RuntimeException(
				'Bu paket Rafımda kanalına ait değil (kanal: ' . (isset($m[1]) ? $m[1] : 'yok') . ', beklenen: ' . FShop::CHANNEL . '). '
				. 'Başka bir projenin paketi kurulmadı'
			);
		}

		if (!preg_match("/const\\s+VERSION\\s*=\\s*'(\\d+\\.\\d+\\.\\d+)'/", $shop, $v) || $v[1] !== $version) {
			throw new RuntimeException('Paket sürümü teklifle uyuşmuyor (pakette: ' . (isset($v[1]) ? $v[1] : '?') . ', teklif: ' . $version . ')');
		}

		foreach (['core/FShop.php', 'core/Upgrade.php', 'core/UpdateInstaller.php', 'core/UpdateChecker.php', 'config/settings.php', 'index.php'] as $php) {
			try {
				token_get_all((string) file_get_contents($root . '/' . $php), TOKEN_PARSE);
			} catch (ParseError $e) {
				throw new RuntimeException('Pakette bozuk PHP dosyası: ' . $php . ' (' . $e->getMessage() . ')');
			}
		}

		$logs[] = 'Paket doğrulandı (kanal ' . FShop::CHANNEL . ', sürüm ' . $version . ')';
	}

	// ------------------------------------------------------------------ indirme / açma

	/**
	 * @param list<string> $logs
	 */
	private static function downloadToFile(string $url, string $dest, array &$logs): bool
	{
		if (self::$downloadOverride !== null) {
			return (bool) (self::$downloadOverride)($url, $dest, $logs);
		}

		if (function_exists('curl_init')) {
			$fp = fopen($dest, 'wb');

			if ($fp === false) {
				$logs[] = 'Hedef dosya yazılamadı';

				return false;
			}

			$ch = curl_init($url);
			$opts = [
				CURLOPT_FILE => $fp,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_CONNECTTIMEOUT => 20,
				CURLOPT_TIMEOUT => 600,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT => 'Rafimda-UpdateInstaller',
				CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
			];
			$ca = UpdateChecker::caBundlePath();
			if ($ca !== '') {
				$opts[CURLOPT_CAINFO] = $ca;
			}
			curl_setopt_array($ch, $opts);
			$ok = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err = curl_error($ch);
			curl_close($ch);
			fclose($fp);

			if ($ok === false || $code < 200 || $code >= 300 || !is_file($dest) || filesize($dest) < 100) {
				$logs[] = 'HTTP indirme hatası' . ($err !== '' ? ': ' . $err : ' (HTTP ' . $code . ')');
				@unlink($dest);

				return false;
			}

			$logs[] = 'İndirildi (' . self::formatBytes((int) filesize($dest)) . ')';

			return true;
		}

		$body = UpdateChecker::httpGet($url, ['User-Agent: Rafimda-UpdateInstaller']);

		if ($body === null || strlen($body) < 100) {
			$logs[] = 'Paket gövdesi boş veya çok küçük';

			return false;
		}

		if (file_put_contents($dest, $body) === false) {
			$logs[] = 'ZIP diske yazılamadı';

			return false;
		}

		$logs[] = 'İndirildi (' . self::formatBytes(strlen($body)) . ')';

		return true;
	}

	private static function extractZip(string $zipPath, string $destDir): void
	{
		$zip = new ZipArchive();
		$open = $zip->open($zipPath, ZipArchive::CHECKCONS);

		if ($open !== true) {
			throw new RuntimeException('ZIP açılamadı veya bozuk (kod ' . $open . ')');
		}

		$destReal = realpath($destDir);

		if ($destReal === false) {
			$zip->close();
			throw new RuntimeException('Extract hedefi geçersiz');
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);

			if ($name === false) {
				continue;
			}

			$rawName = $name;
			$name = str_replace('\\', '/', $name);

			if ($name === '' || substr($name, -1) === '/') {
				continue;
			}

			if (Security::isUnsafeZipPath($name) || Security::isZipEntrySymlink($zip, $i)) {
				$zip->close();
				throw new RuntimeException('ZIP path traversal engellendi: ' . $name);
			}

			$target = $destReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
			$dir = dirname($target);

			if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
				$zip->close();
				throw new RuntimeException('Dizin oluşturulamadı: ' . $name);
			}

			$resolvedDir = realpath($dir);

			if ($resolvedDir === false || strpos($resolvedDir, $destReal) !== 0) {
				$zip->close();
				throw new RuntimeException('ZIP çıkış yolu güvenli değil: ' . $name);
			}

			$stream = $zip->getStream($rawName);

			if ($stream === false) {
				$zip->close();
				throw new RuntimeException('ZIP girdisi okunamadı: ' . $name);
			}

			$out = fopen($target, 'wb');

			if ($out === false) {
				fclose($stream);
				$zip->close();
				throw new RuntimeException('Dosya yazılamadı: ' . $name . ' (disk dolu veya izin yok)');
			}

			stream_copy_to_stream($stream, $out);
			fclose($out);
			fclose($stream);
		}

		$zip->close();
	}

	private static function resolvePackageRoot(string $extractDir): string
	{
		$entries = array_values(array_filter(scandir($extractDir) ?: [], static function ($e) {
			return $e !== '.' && $e !== '..';
		}));

		if (count($entries) === 1) {
			$only = $extractDir . '/' . $entries[0];

			if (is_dir($only)) {
				// GitHub zipball: owner-repo-sha/ — or rafimda-3.0.3/
				return $only;
			}
		}

		return $extractDir;
	}

	// ------------------------------------------------------------------ kopyalama / yedek / geri alma

	/**
	 * Korumalı yollar atlanır. Aynı içerikli dosyalar kopyalanmaz. Üzerine yazılacak her dosya önce $backupDir'e yedeklenir.
	 *
	 * @param array{replaced:list<string>,created:list<string>} $manifest
	 * @param list<string> $logs
	 */
	private static function copyPackage(string $from, string $to, string $backupDir, array &$manifest, array &$logs): int
	{
		$from = rtrim(str_replace('\\', '/', $from), '/');
		$to = rtrim(str_replace('\\', '/', $to), '/');
		$count = 0;
		$skipped = 0;
		$same = 0;

		if (!is_dir($backupDir) && !@mkdir($backupDir . '/files', 0755, true)) {
			throw new RuntimeException('Yedek dizini oluşturulamadı: ' . basename($backupDir) . ' (backups/ yazılabilir olmalı)');
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		/** @var SplFileInfo $file */
		foreach ($iterator as $file) {
			$full = str_replace('\\', '/', $file->getPathname());
			$rel = ltrim(substr($full, strlen($from)), '/');

			if ($rel === '') {
				continue;
			}

			if (self::isProtectedPath($rel)) {
				$skipped++;

				if ($rel === '.htaccess' && is_file($to . '/.htaccess') && md5_file($full) !== md5_file($to . '/.htaccess')) {
					$logs[] = 'Not: kök .htaccess korunduğu için güncellenmedi (pakette farklı); gerekirse elle birleştirin';
				}

				continue;
			}

			$dest = $to . '/' . $rel;

			if ($file->isDir()) {
				if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
					throw new RuntimeException('Dizin oluşturulamadı: ' . $rel);
				}

				continue;
			}

			$dir = dirname($dest);

			if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
				throw new RuntimeException('Hedef dizin oluşturulamadı: ' . $rel);
			}

			$exists = is_file($dest);

			if ($exists && filesize($dest) === filesize($full) && md5_file($dest) === md5_file($full)) {
				$same++;
				continue;
			}

			if ($exists) {
				$bk = $backupDir . '/files/' . $rel;

				if (!is_dir(dirname($bk)) && !@mkdir(dirname($bk), 0755, true)) {
					throw new RuntimeException('Yedek dizini oluşturulamadı: ' . $rel);
				}

				if (!@copy($dest, $bk)) {
					throw new RuntimeException('Yedeklenemedi: ' . $rel . ' (disk dolu olabilir)');
				}

				$manifest['replaced'][] = $rel;
			} else {
				$manifest['created'][] = $rel;
			}

			if (self::$copyHook !== null) {
				(self::$copyHook)($rel, $dest);
			}

			// Önce geçici dosyaya yaz, sonra yerine taşı: yazma ortasında kesilirse hedef dosya yarım kalmaz.
			$tmp = $dest . '.upd-tmp';

			if (!@copy($file->getPathname(), $tmp) || (filesize($tmp) !== filesize($full))) {
				@unlink($tmp);
				throw new RuntimeException('Kopyalanamadı: ' . $rel . ' (izin/disk alanı kontrol edin)');
			}

			if (!@rename($tmp, $dest)) {
				@unlink($tmp);
				throw new RuntimeException('Yerine konamadı: ' . $rel);
			}

			$count++;
		}

		@file_put_contents($backupDir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$logs[] = 'Atlanan korumalı öğe: ' . $skipped . '; aynı içerik: ' . $same;

		return $count;
	}

	/**
	 * @param array{replaced:list<string>,created:list<string>} $manifest
	 * @param list<string> $logs
	 */
	private static function rollback(string $backupDir, array $manifest, array &$logs): bool
	{
		$root = self::rootDir();
		$ok = true;

		foreach ($manifest['replaced'] as $rel) {
			$src = $backupDir . '/files/' . $rel;

			if (is_file($src)) {
				if (!@copy($src, $root . '/' . $rel)) {
					$ok = false;
					$logs[] = 'Geri alınamadı: ' . $rel;
				}
			}
		}

		foreach ($manifest['created'] as $rel) {
			$path = $root . '/' . $rel;

			if (is_file($path) && !@unlink($path)) {
				$ok = false;
				$logs[] = 'Silinemedi (yeni dosya): ' . $rel;
			}
		}

		clearstatcache(true);
		self::resetOpcache($manifest);
		self::log('Geri alma ' . ($ok ? 'tamamlandı' : 'EKSİK') . ': ' . count($manifest['replaced']) . ' dosya geri yüklendi, ' . count($manifest['created']) . ' yeni dosya silindi');

		return $ok;
	}

	/** @param array{replaced:list<string>,created:list<string>} $manifest */
	private static function resetOpcache(array $manifest): void
	{
		if (function_exists('opcache_invalidate')) {
			foreach (array_merge($manifest['replaced'], $manifest['created']) as $rel) {
				if (substr($rel, -4) === '.php') {
					@opcache_invalidate(self::rootDir() . '/' . $rel, true);
				}
			}
		}

		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}
	}

	private static function pruneBackups(): void
	{
		$dirs = glob(self::backupRoot() . '/update-*', GLOB_ONLYDIR) ?: [];
		sort($dirs);

		while (count($dirs) > self::KEEP_BACKUPS) {
			self::rrmdir((string) array_shift($dirs));
		}
	}

	public static function isProtectedPath(string $relative): bool
	{
		$relative = ltrim(str_replace('\\', '/', $relative), '/');
		$lower = strtolower($relative);

		foreach (self::protectedPrefixes() as $prefix) {
			$prefix = strtolower(str_replace('\\', '/', $prefix));

			if (substr($prefix, -1) === '/') {
				if ($lower === rtrim($prefix, '/') || strpos($lower, $prefix) === 0) {
					return true;
				}
			} elseif ($lower === $prefix) {
				return true;
			}
		}

		return false;
	}

	private static function formatBytes(int $bytes): string
	{
		if ($bytes < 1024) {
			return $bytes . ' B';
		}

		if ($bytes < 1048576) {
			return round($bytes / 1024, 1) . ' KB';
		}

		return round($bytes / 1048576, 1) . ' MB';
	}

	private static function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$items = scandir($dir) ?: [];

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;

			if (is_dir($path) && !is_link($path)) {
				self::rrmdir($path);
			} else {
				@unlink($path);
			}
		}

		@rmdir($dir);
	}
}
