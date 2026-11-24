<?php

/**
 * Pazaryeri bağdaştırıcı sözleşmesi. Her pazaryeri BAĞIMSIZ bir sınıftır; kendi kimlik alanlarını, bağlantı testini
 * ve ürün gönderimini tanımlar. implemented() false ise sistem bu pazaryerine ASLA gönderim yapmış gibi davranmaz.
 */
interface MhAdapter
{
	/** Kısa anahtar: trendyol, hepsiburada, n11, amazon, pazarama, ozon */
	public function key(): string;

	public function title(): string;

	/** Gerçek API entegrasyonu yazıldı ve test edildi mi? Şu an tüm yerleşik bağdaştırıcılar için false. */
	public function implemented(): bool;

	/**
	 * Kimlik alanları: ad => ['label'=>..., 'secret'=>bool, 'required'=>bool, 'max'=>int]
	 *
	 * @return array<string,array{label:string,secret:bool,required:bool,max:int}>
	 */
	public function credentialFields(): array;

	/** @param array<string,string> $credentials @return array{success:bool,message:string} */
	public function testConnection(array $credentials): array;

	/**
	 * Ürünü pazaryerine gönderir.
	 *
	 * @param array<string,string> $credentials
	 * @param array<string,mixed> $payload MhPayload::build çıktısı
	 * @return array{success:bool, remote_id:string, error:string, retryable:bool}
	 */
	public function submit(array $credentials, array $payload): array;
}
