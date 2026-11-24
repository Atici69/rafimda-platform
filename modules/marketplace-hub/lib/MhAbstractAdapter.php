<?php

/** Henüz gerçek API bağlantısı OLMAYAN bağdaştırıcıların ortak tabanı: hiçbir çağrı yapmaz, sahte başarı üretmez. */
abstract class MhAbstractAdapter implements MhAdapter
{
	public function implemented(): bool
	{
		return false;
	}

	public function testConnection(array $credentials): array
	{
		return ['success' => false, 'message' => $this->title() . ' için gerçek API entegrasyonu henüz yapılmadı; bağlantı test edilemez.'];
	}

	public function submit(array $credentials, array $payload): array
	{
		return ['success' => false, 'remote_id' => '', 'error' => $this->title() . ' API entegrasyonu henüz yapılmadı; ürün gönderilmedi.', 'retryable' => false];
	}

	/** @return array{label:string,secret:bool,required:bool,max:int} */
	protected static function f(string $label, bool $secret = false, bool $required = true, int $max = 200): array
	{
		return ['label' => $label, 'secret' => $secret, 'required' => $required, 'max' => $max];
	}
}
