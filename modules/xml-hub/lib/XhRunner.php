<?php

/**
 * Kuyruk ve parçalı işleme. İş kuyruğu xh_runs tablosudur; her çalıştırma bir zaman bütçesi içinde işlenir, ilerleme
 * (item_offset) her üründen sonra kaydedilir; süreç kesilirse kaldığı yerden devam eder. Aynı çalıştırmayı iki işçi
 * birden alamaz (lock_token). Dış kaynak hatalarında üstel geri çekilmeyle yeniden denenir.
 *
 * ZAMANLAMA: PHP kendi başına zamanlayıcı değildir. `xml-cron` rotasının (gizli anahtarlı) sunucu cron'u veya harici
 * bir servis tarafından düzenli (ör. dakikada bir) çağrılması GEREKİR; çağrılmazsa otomatik senkronizasyon çalışmaz.
 */
class XhRunner
{
	public const STALE_LOCK_SECONDS = 300;
	public const RETRY_ITEM_MAX_ATTEMPTS = 3;
	public const RETRY_BASE_SECONDS = 60;

	/** @return array{success:bool,message:string,id:int} */
	public static function enqueue(int $idConn, string $kind, ?array $selectedKeys = null, ?int $vendor = null): array
	{
		$conn = XhRepo::conn($idConn, $vendor);

		if (!$conn) {
			return ['success' => false, 'message' => 'Bağlantı bulunamadı.', 'id' => 0];
		}

		if (!in_array($kind, ['import', 'sync', 'retry'], true)) {
			return ['success' => false, 'message' => 'Geçersiz işlem türü.', 'id' => 0];
		}

		if ((XhRepo::mapping($conn)['fields']['name'] ?? '') === '' && $kind !== 'retry') {
			return ['success' => false, 'message' => 'Önce alan eşleştirmesini kaydedin (en az ürün adı ve fiyat).', 'id' => 0];
		}

		if (DB::getRowSafe('xh_runs', "id_connection = ? AND status IN ('queued','running')", [$idConn])) {
			return ['success' => false, 'message' => 'Bu bağlantı için zaten bekleyen/çalışan bir işlem var.', 'id' => 0];
		}

		$id = DB::insert('xh_runs', [
			'id_connection' => $idConn, 'id_vendor' => (int) $conn['id_vendor'], 'kind' => $kind, 'status' => 'queued',
			'selected' => $selectedKeys === null ? null : json_encode(array_values(array_slice(array_map('strval', $selectedKeys), 0, 5000))),
			'next_attempt_at' => XhRepo::now(),
		]);

		return $id ? ['success' => true, 'message' => 'İşlem kuyruğa alındı.', 'id' => (int) $id] : ['success' => false, 'message' => 'Kuyruğa alınamadı.', 'id' => 0];
	}

	public static function cancel(int $idRun, ?int $vendor): bool
	{
		$run = XhRepo::run($idRun, $vendor);

		if (!$run || !in_array($run['status'], ['queued', 'running'], true)) {
			return false;
		}

		DB::update('xh_runs', ['status' => 'cancelled', 'finished_at' => XhRepo::now(), 'message' => 'Kullanıcı iptal etti.', 'lock_token' => ''], 'id_run = :i', ['i' => $idRun]);
		self::dropSnapshot($run);

		return true;
	}

	/** Zamanı gelen otomatik senkronizasyonları kuyruğa alır. @return int kuyruğa alınan sayı */
	public static function scheduleDue(): int
	{
		$n = 0;
		$due = DB::execute("SELECT id_connection FROM xh_connections WHERE status = 'active' AND interval_minutes > 0 AND (next_run_at IS NULL OR next_run_at <= ?)", [XhRepo::now()]) ?: [];

		foreach ($due as $d) {
			$r = self::enqueue((int) $d['id_connection'], 'sync');

			if ($r['success']) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * İşçi: zaman bütçesi dolana kadar kuyruğu işler.
	 *
	 * @return array{runs:int, items:int, scheduled:int}
	 */
	public static function tick(int $budgetSeconds = 20, int $maxItems = 1000, ?int $onlyVendor = null): array
	{
		$deadline = microtime(true) + max(1, $budgetSeconds);
		$scheduled = $onlyVendor === null ? self::scheduleDue() : 0;
		$runs = 0;
		$items = 0;
		$open = DB::execute(
			"SELECT id_run FROM xh_runs WHERE status IN ('queued','running') AND (next_attempt_at IS NULL OR next_attempt_at <= ?)" . ($onlyVendor === null ? '' : ' AND id_vendor = ?') . ' ORDER BY id_run ASC LIMIT 10',
			$onlyVendor === null ? [XhRepo::now()] : [XhRepo::now(), $onlyVendor]
		) ?: [];

		foreach ($open as $o) {
			if (microtime(true) >= $deadline || $items >= $maxItems) {
				break;
			}

			$done = self::processRun((int) $o['id_run'], $deadline, $maxItems - $items);

			if ($done >= 0) {
				$runs++;
				$items += $done;
			}
		}

		return ['runs' => $runs, 'items' => $items, 'scheduled' => $scheduled];
	}

	private static function claim(int $idRun): ?string
	{
		$token = bin2hex(random_bytes(8));
		DB::execute(
			"UPDATE xh_runs SET lock_token = ?, locked_at = ? WHERE id_run = ? AND status IN ('queued','running') AND (lock_token = '' OR locked_at < ?)",
			[$token, XhRepo::now(), $idRun, date('Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS)]
		);
		$cur = DB::getValue('SELECT lock_token FROM xh_runs WHERE id_run = ?', [$idRun]);

		return $cur === $token ? $token : null;
	}

	private static function release(int $idRun): void
	{
		DB::execute("UPDATE xh_runs SET lock_token = '', locked_at = NULL WHERE id_run = ?", [$idRun]);
	}

	private static function dropSnapshot(array $run): void
	{
		if (!empty($run['snapshot_path'])) {
			XhStorage::remove((string) $run['snapshot_path']);
		}
	}

	/** Dış/geçici hata: yeniden dene ya da kalıcı başarısız say. */
	private static function retryOrFail(array $run, array $conn, string $message): void
	{
		$attempts = (int) $run['attempts'] + 1;
		$idConn = (int) $conn['id_connection'];
		XhRepo::logError($idConn, (int) $run['id_run'], '', 'Deneme ' . $attempts . '/' . (int) $run['max_attempts'] . ': ' . $message);

		if ($attempts >= (int) $run['max_attempts']) {
			DB::update('xh_runs', ['status' => 'failed', 'attempts' => $attempts, 'message' => mb_substr($message, 0, 255), 'finished_at' => XhRepo::now(), 'lock_token' => ''], 'id_run = :i', ['i' => $run['id_run']]);
			self::dropSnapshot($run);
			XhRepo::setConn($idConn, ['last_error' => mb_substr($message, 0, 255), 'fail_count' => (int) $conn['fail_count'] + 1, 'status' => 'error']);

			return;
		}

		$wait = self::RETRY_BASE_SECONDS * (4 ** ($attempts - 1)); // 60 sn, 240 sn, ...
		DB::update('xh_runs', ['status' => 'queued', 'attempts' => $attempts, 'message' => 'Yeniden denenecek: ' . mb_substr($message, 0, 200), 'next_attempt_at' => date('Y-m-d H:i:s', time() + $wait), 'lock_token' => ''], 'id_run = :i', ['i' => $run['id_run']]);
	}

	private static function failPermanently(array $run, array $conn, string $message): void
	{
		XhRepo::logError((int) $conn['id_connection'], (int) $run['id_run'], '', $message);
		DB::update('xh_runs', ['status' => 'failed', 'message' => mb_substr($message, 0, 255), 'finished_at' => XhRepo::now(), 'lock_token' => ''], 'id_run = :i', ['i' => $run['id_run']]);
		self::dropSnapshot($run);
		XhRepo::setConn((int) $conn['id_connection'], ['last_error' => mb_substr($message, 0, 255), 'fail_count' => (int) $conn['fail_count'] + 1, 'status' => 'error']);
	}

	private static function bump(array &$run, string $outcome): void
	{
		$map = ['created' => 'created', 'updated' => 'updated', 'unchanged' => 'unchanged', 'failed' => 'failed', 'skipped' => 'skipped'];

		if (isset($map[$outcome])) {
			$run[$map[$outcome]] = (int) $run[$map[$outcome]] + 1;
		}
	}

	private static function saveProgress(array $run): void
	{
		DB::update('xh_runs', ['item_offset' => $run['item_offset'], 'created' => $run['created'], 'updated' => $run['updated'], 'unchanged' => $run['unchanged'], 'failed' => $run['failed'], 'skipped' => $run['skipped']], 'id_run = :i', ['i' => $run['id_run']]);
	}

	/** @return int işlenen ürün sayısı; çalıştırma alınamadıysa -1 */
	public static function processRun(int $idRun, float $deadline, int $maxItems): int
	{
		if (self::claim($idRun) === null) {
			return -1;
		}

		$run = DB::execute('SELECT * FROM xh_runs WHERE id_run = ?', [$idRun])[0] ?? null;
		$conn = $run ? XhRepo::conn((int) $run['id_connection'], null) : null;

		if (!$run || !in_array($run['status'], ['queued', 'running'], true)) {
			self::release($idRun);

			return -1;
		}

		if (!$conn || (int) $conn['id_vendor'] !== (int) $run['id_vendor']) {
			self::failPermanently($run, $conn ?: ['id_connection' => (int) $run['id_connection'], 'fail_count' => 0], 'Bağlantı bulunamadı veya sahibi uyuşmuyor.');

			return 0;
		}

		try {
			return $run['kind'] === 'retry' ? self::processRetry($run, $conn, $deadline, $maxItems) : self::processXml($run, $conn, $deadline, $maxItems);
		} catch (Throwable $e) {
			error_log('xml-hub run ' . $idRun . ': ' . $e->getMessage());
			$fresh = DB::execute('SELECT * FROM xh_runs WHERE id_run = ?', [$idRun])[0] ?? $run;
			self::retryOrFail($fresh, $conn, 'İşlem hatası: ' . mb_substr($e->getMessage(), 0, 200));

			return 0;
		}
	}

	private static function finish(array $run, array $conn, string $note = ''): void
	{
		self::saveProgress($run);
		$msg = $run['created'] . ' yeni, ' . $run['updated'] . ' güncel, ' . $run['unchanged'] . ' değişmedi, ' . $run['skipped'] . ' atlandı, ' . $run['failed'] . ' hatalı' . $note;
		DB::update('xh_runs', ['status' => 'done', 'message' => mb_substr($msg, 0, 255), 'finished_at' => XhRepo::now(), 'lock_token' => '', 'locked_at' => null], 'id_run = :i', ['i' => $run['id_run']]);
		self::dropSnapshot($run);
		$upd = ['last_run_at' => XhRepo::now(), 'last_error' => '', 'fail_count' => 0, 'next_run_at' => (int) $conn['interval_minutes'] > 0 ? date('Y-m-d H:i:s', time() + (int) $conn['interval_minutes'] * 60) : null];

		if ($conn['status'] !== 'paused') {
			$upd['status'] = 'active';
		}

		XhRepo::setConn((int) $conn['id_connection'], $upd);
	}

	private static function processXml(array $run, array $conn, float $deadline, int $maxItems): int
	{
		$idRun = (int) $run['id_run'];
		$path = (string) $run['snapshot_path'];

		$prepare = $run['started_at'] === null || ($conn['source_type'] === 'url' && $path === '');

		if ($prepare) {
			if ($conn['source_type'] === 'url') {
				$path = XhStorage::newPath('snap');
				$f = XhHttp::fetchToFile((string) $conn['url'], $path);

				if (!$f['ok']) {
					@unlink($path);
					self::retryOrFail($run, $conn, 'İndirilemedi: ' . $f['error']);

					return 0;
				}
			} else {
				$path = (string) $conn['file_path'];

				if (!XhStorage::isInside($path)) {
					self::failPermanently($run, $conn, 'Yüklenen dosya bulunamadı.');

					return 0;
				}
			}

			$v = XhXml::validate($path);

			if (!$v['ok']) {
				if ($conn['source_type'] === 'url') {
					XhStorage::remove($path);
				}

				self::failPermanently($run, $conn, 'XML reddedildi: ' . $v['error']);

				return 0;
			}

			$itemPath = (string) $conn['item_path'] !== '' ? (string) $conn['item_path'] : (XhXml::detectItemPath($path) ?? '');

			if ($itemPath === '') {
				if ($conn['source_type'] === 'url') {
					XhStorage::remove($path);
				}

				self::failPermanently($run, $conn, 'Ürün düğümü bulunamadı; bağlantıda ürün yolunu belirtin.');

				return 0;
			}

			if ((string) $conn['item_path'] === '') {
				XhRepo::setConn((int) $conn['id_connection'], ['item_path' => $itemPath]);
				$conn['item_path'] = $itemPath;
			}

			$total = XhXml::countItems($path, $itemPath);
			DB::update('xh_runs', ['snapshot_path' => $conn['source_type'] === 'url' ? $path : '', 'total' => $total, 'status' => 'running', 'started_at' => XhRepo::now()], 'id_run = :i', ['i' => $idRun]);
			$run['snapshot_path'] = $conn['source_type'] === 'url' ? $path : '';
			$run['total'] = $total;
		} elseif ($conn['source_type'] === 'file') {
			$path = (string) $conn['file_path'];
		}

		if ($path === '' || !is_file($path)) {
			self::retryOrFail($run, $conn, 'Anlık görüntü dosyası kayboldu.');
			DB::update('xh_runs', ['snapshot_path' => ''], 'id_run = :i', ['i' => $idRun]);

			return 0;
		}

		$selected = null;

		if ($run['selected'] !== null && $run['selected'] !== '') {
			$selected = array_fill_keys((array) json_decode((string) $run['selected'], true), true);
		}

		$processed = 0;
		$offset = (int) $run['item_offset'];
		$exhausted = true;

		foreach (XhXml::stream($path, (string) $conn['item_path'], $offset) as $idx => $raw) {
			$res = XhImporter::process($conn, $run, $raw, $selected);
			self::bump($run, $res['outcome']);
			$run['item_offset'] = $idx + 1;
			$processed++;
			self::saveProgress($run);

			if (microtime(true) >= $deadline || $processed >= $maxItems) {
				$exhausted = false;
				break;
			}
		}

		if ($exhausted) {
			self::finish($run, $conn);
		} else {
			DB::update('xh_runs', ['status' => 'running', 'lock_token' => '', 'locked_at' => null], 'id_run = :i', ['i' => $idRun]);
		}

		return $processed;
	}

	private static function processRetry(array $run, array $conn, float $deadline, int $maxItems): int
	{
		$idRun = (int) $run['id_run'];
		$idConn = (int) $conn['id_connection'];
		$processed = 0;

		if ($run['status'] === 'queued') {
			$total = (int) DB::getValue("SELECT COUNT(*) FROM xh_items WHERE id_connection = ? AND state = 'failed' AND attempts < ? AND data IS NOT NULL AND data <> ''", [$idConn, self::RETRY_ITEM_MAX_ATTEMPTS]);
			DB::update('xh_runs', ['status' => 'running', 'total' => $total, 'started_at' => XhRepo::now()], 'id_run = :i', ['i' => $idRun]);
			$run['total'] = $total;
		}

		while (microtime(true) < $deadline && $processed < $maxItems) {
			$rows = DB::execute("SELECT * FROM xh_items WHERE id_connection = ? AND state = 'failed' AND attempts < ? AND data IS NOT NULL AND data <> '' AND id_item > ? ORDER BY id_item ASC LIMIT 20", [$idConn, self::RETRY_ITEM_MAX_ATTEMPTS, (int) $run['item_offset']]) ?: [];

			if ($rows === []) {
				self::finish($run, $conn, ' (yeniden deneme)');

				return $processed;
			}

			foreach ($rows as $r) {
				$raw = json_decode((string) $r['data'], true);
				$run['item_offset'] = (int) $r['id_item'];

				if (!is_array($raw)) {
					continue;
				}

				$res = XhImporter::process($conn, $run, $raw, null);
				self::bump($run, $res['outcome']);
				$processed++;
				self::saveProgress($run);
			}
		}

		DB::update('xh_runs', ['status' => 'running', 'lock_token' => '', 'locked_at' => null], 'id_run = :i', ['i' => $idRun]);

		return $processed;
	}
}
