<?php

/**
 * Gönderim kuyruğu. Ürünler mh_listings'e alınır; gerçek bağdaştırıcı yoksa kayıt 'blocked' olur (gönderilmiş gibi gösterilmez).
 * Hatalarda üstel geri çekilmeli yeniden deneme (60 sn, 240 sn; en çok 3 deneme). Her ürün sahiplik kapısından geçer.
 */
class MhDispatcher
{
	public const MAX_ATTEMPTS = 3;
	public const STALE_SECONDS = 600;

	/** @param list<int> $productIds @return array{queued:int, blocked:int, rejected:int, messages:list<string>} */
	public static function queue(int $idAccount, ?int $vendor, array $productIds): array
	{
		$out = ['queued' => 0, 'blocked' => 0, 'rejected' => 0, 'messages' => []];
		$acc = MhRepo::account($idAccount, $vendor);
		$a = $acc ? MhRegistry::get((string) $acc['marketplace']) : null;

		if (!$acc || !$a) {
			$out['messages'][] = 'Hesap bulunamadı.';

			return $out;
		}

		$owner = (int) $acc['id_vendor'];

		foreach (array_slice(array_values(array_unique(array_map('intval', $productIds))), 0, 500) as $pid) {
			if (!MhOwner::owns($owner, $pid)) {
				$out['rejected']++;

				continue;
			}

			$b = MhPayload::build($pid, $idAccount);
			$state = 'queued';
			$err = '';

			if (!$b['ok']) {
				$state = 'blocked';
				$err = $b['error'];
			} elseif (!$a->implemented()) {
				$state = 'blocked';
				$err = $a->title() . ' API entegrasyonu henüz yapılmadı; gönderilmedi.';
			}

			$row = ['state' => $state, 'error' => mb_substr($err, 0, 255), 'attempts' => 0, 'next_attempt_at' => self::now(), 'date_upd' => self::now(), 'id_vendor' => $owner];
			$ex = DB::execute('SELECT id_listing, state FROM mh_listings WHERE id_account = ? AND id_product = ?', [$idAccount, $pid]);

			if ($ex && isset($ex[0])) {
				if ($ex[0]['state'] === 'sending') {
					continue;
				}

				DB::update('mh_listings', $row, 'id_listing = :i', ['i' => (int) $ex[0]['id_listing']]);
			} else {
				DB::insert('mh_listings', $row + ['id_account' => $idAccount, 'id_product' => $pid]);
			}

			$out[$state === 'queued' ? 'queued' : 'blocked']++;

			if ($state === 'blocked') {
				MhRepo::logError($idAccount, $pid, 'blocked', $err);
			}
		}

		return $out;
	}

	private static function now(): string
	{
		return MhRepo::now();
	}

	/** @return array{processed:int, sent:int, failed:int, retried:int} */
	public static function tick(int $limit = 20): array
	{
		$res = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0];
		DB::execute("UPDATE mh_listings SET state = 'queued', error = '' WHERE state = 'sending' AND date_upd < ?", [date('Y-m-d H:i:s', time() - self::STALE_SECONDS)]);
		$due = DB::execute("SELECT id_listing FROM mh_listings WHERE state = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id_listing ASC LIMIT " . max(1, min(200, $limit)), [self::now()]) ?: [];

		foreach ($due as $d) {
			$id = (int) $d['id_listing'];
			$token = bin2hex(random_bytes(8));
			DB::execute("UPDATE mh_listings SET state = 'sending', error = ?, date_upd = ? WHERE id_listing = ? AND state = 'queued'", ['claim:' . $token, self::now(), $id]);
			$l = DB::execute('SELECT * FROM mh_listings WHERE id_listing = ?', [$id]);

			if (!$l || $l[0]['state'] !== 'sending' || $l[0]['error'] !== 'claim:' . $token) {
				continue; // başka işçi aldı
			}

			$res['processed']++;
			$o = self::process($l[0]);
			$res[$o]++;
		}

		return $res;
	}

	/** @param array<string,mixed> $l @return string sent|failed|retried */
	private static function process(array $l): string
	{
		$id = (int) $l['id_listing'];
		$idAcc = (int) $l['id_account'];
		$pid = (int) $l['id_product'];
		$acc = MhRepo::account($idAcc, null);
		$a = $acc ? MhRegistry::get((string) $acc['marketplace']) : null;
		$set = static function (array $f) use ($id): void {
			DB::update('mh_listings', $f + ['date_upd' => MhRepo::now()], 'id_listing = :i', ['i' => $id]);
		};

		if (!$acc || !$a || !MhOwner::owns((int) $l['id_vendor'], $pid)) {
			$set(['state' => 'failed', 'error' => 'Hesap/ürün artık erişilebilir değil.']);
			MhRepo::logError($idAcc, $pid, 'gone', 'Hesap/ürün artık erişilebilir değil.');

			return 'failed';
		}

		if (!$a->implemented()) {
			$set(['state' => 'blocked', 'error' => $a->title() . ' API entegrasyonu henüz yapılmadı; gönderilmedi.']);

			return 'failed';
		}

		$b = MhPayload::build($pid, $idAcc);

		if (!$b['ok']) {
			$set(['state' => 'blocked', 'error' => $b['error']]);
			MhRepo::logError($idAcc, $pid, 'payload', $b['error']);

			return 'failed';
		}

		try {
			$r = $a->submit(MhRepo::credentials($idAcc), $b['payload']);
		} catch (Throwable $e) {
			$r = ['success' => false, 'remote_id' => '', 'error' => 'Beklenmeyen hata: ' . mb_substr($e->getMessage(), 0, 150), 'retryable' => true];
		}

		if (!empty($r['success'])) {
			$set(['state' => 'sent', 'remote_id' => mb_substr((string) $r['remote_id'], 0, 100), 'payload_hash' => MhPayload::hash($b['payload']), 'error' => '', 'attempts' => 0]);

			return 'sent';
		}

		$attempts = (int) $l['attempts'] + 1;
		$msg = mb_substr((string) $r['error'], 0, 255);
		MhRepo::logError($idAcc, $pid, !empty($r['retryable']) ? 'retry' : 'rejected', $msg);

		if (!empty($r['retryable']) && $attempts < self::MAX_ATTEMPTS) {
			$set(['state' => 'queued', 'attempts' => $attempts, 'error' => $msg, 'next_attempt_at' => date('Y-m-d H:i:s', time() + 60 * (4 ** ($attempts - 1)))]);

			return 'retried';
		}

		$set(['state' => 'failed', 'attempts' => $attempts, 'error' => $msg]);

		return 'failed';
	}

	/** Başarısız kayıtları yeniden kuyruğa alır. @return int sayı */
	public static function retryFailed(int $idAccount, ?int $vendor): int
	{
		if (!MhRepo::account($idAccount, $vendor)) {
			return 0;
		}

		$n = (int) DB::getValue("SELECT COUNT(*) FROM mh_listings WHERE id_account = ? AND state = 'failed'", [$idAccount]);
		DB::execute("UPDATE mh_listings SET state = 'queued', attempts = 0, error = '', next_attempt_at = ? WHERE id_account = ? AND state = 'failed'", [self::now(), $idAccount]);

		return $n;
	}
}
