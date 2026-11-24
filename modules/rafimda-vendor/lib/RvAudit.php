<?php

class RvAudit
{
	/** @param array<string,mixed> $meta */
	public static function log(?int $idVendor, string $action, string $entity = '', int $entityId = 0, array $meta = [], int $idAdmin = 0): void
	{
		$idUser = 0;

		if (class_exists('Customer', false) && Customer::isLoggedIn()) {
			$idUser = Customer::getId();
		}

		DB::insert('rafimda_audit_log', [
			'id_vendor' => $idVendor,
			'id_user' => $idUser,
			'id_admin' => $idAdmin,
			'action' => mb_substr($action, 0, 64),
			'entity' => mb_substr($entity, 0, 32),
			'entity_id' => $entityId,
			'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
			'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
		]);
	}

	/** @return list<array<string,mixed>> */
	public static function recent(int $idVendor, int $limit = 20): array
	{
		$limit = max(1, min(100, $limit));

		return DB::execute(
			'SELECT * FROM rafimda_audit_log WHERE id_vendor = ? ORDER BY id_log DESC LIMIT ' . $limit,
			[$idVendor]
		) ?: [];
	}
}
