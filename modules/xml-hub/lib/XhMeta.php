<?php

/**
 * Ürün kaynağı ve XML'e özgü ek veriler (çekirdek products tablosuna dokunmadan).
 * Kaynak: 'manual' (kayıt yok = manuel), 'xml' (bağlantı + tedarikçi). Kargo ücreti ve içerik parmak izleri burada tutulur.
 */
class XhMeta
{
	/** @return array<string,mixed>|null */
	public static function get(int $idProduct): ?array
	{
		$r = DB::execute('SELECT * FROM xh_product_meta WHERE id_product = ?', [$idProduct]);

		return $r && isset($r[0]) ? $r[0] : null;
	}

	/** @param array<string,mixed> $data */
	public static function save(int $idProduct, int $idVendor, array $data): void
	{
		$allowed = ['source_type', 'id_connection', 'id_supplier', 'cargo_price', 'desc_hash', 'image_urls'];
		$data = array_intersect_key($data, array_flip($allowed));

		if (self::get($idProduct)) {
			if ($data !== []) {
				DB::update('xh_product_meta', $data + ['date_upd' => XhRepo::now()], 'id_product = :pid', ['pid' => $idProduct]);
			}

			return;
		}

		DB::insert('xh_product_meta', $data + ['id_product' => $idProduct, 'id_vendor' => $idVendor, 'source_type' => 'xml']);
	}

	/** Ürünün kaynağı: manual | xml. Kaydı olmayan (elle eklenen) ürünler 'manual'dır. @return array{source:string,id_connection:int,id_supplier:int} */
	public static function source(int $idProduct): array
	{
		$m = self::get($idProduct);

		return $m && $m['source_type'] === 'xml'
			? ['source' => 'xml', 'id_connection' => (int) $m['id_connection'], 'id_supplier' => (int) $m['id_supplier']]
			: ['source' => 'manual', 'id_connection' => 0, 'id_supplier' => 0];
	}

	public static function addChange(int $idProduct, int $idConn, int $idRun, string $kind, string $summary): void
	{
		DB::insert('xh_changes', ['id_product' => $idProduct, 'id_connection' => $idConn, 'id_run' => $idRun, 'kind' => mb_substr($kind, 0, 16), 'summary' => mb_substr($summary, 0, 255)]);
	}

	public static function fingerprint(string $text): string
	{
		return sha1(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
	}
}
