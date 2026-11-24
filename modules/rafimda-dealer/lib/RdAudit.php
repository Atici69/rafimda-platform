<?php

class RdAudit
{
	/** @param array<string,mixed> $meta */
	public static function log(?int $idDealer, string $action, string $entity = '', int $entityId = 0, array $meta = [], int $idAdmin = 0): void
	{
		DB::insert('rd_audit_log', [
			'id_dealer' => $idDealer,
			'id_user' => (class_exists('Customer') && Customer::isLoggedIn()) ? Customer::getId() : 0,
			'id_admin' => $idAdmin,
			'action' => mb_substr($action, 0, 64),
			'entity' => mb_substr($entity, 0, 32),
			'entity_id' => $entityId,
			'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
			'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
		]);
	}
}
