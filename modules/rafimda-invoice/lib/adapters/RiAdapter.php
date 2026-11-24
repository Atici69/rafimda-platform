<?php

/**
 * Entegratör bağdaştırıcı sözleşmesi. Tek firmaya bağımlı kalmamak için her özel entegratör bu arayüzü uygular.
 * Kimlik bilgileri çağırana verilmez; bağdaştırıcı yalnızca send/status/query sonucunu döndürür (token/şifre loglanmaz).
 */
interface RiAdapter
{
	public function code(): string;

	public function label(): string;

	/** Gerçek gönderime hazır mı (resmi API + sözleşme + test hesabı ile doğrulandı mı)? */
	public function isImplemented(): bool;

	/** @return array{success:bool,message:string} */
	public function testConnection(array $account): array;

	/** Alıcı GİB e-Fatura kayıtlı kullanıcısı mı? null = bilinmiyor (sorgulanamadı). */
	public function isEInvoiceUser(array $account, string $taxNo): ?bool;

	/** @return array{success:bool,status:string,ettn?:string,invoice_no?:string,message:string} */
	public function send(array $account, array $invoice): array;

	/** @return array{success:bool,status:string,message:string} */
	public function status(array $account, array $invoice): array;

	/** @return array{success:bool,message:string} */
	public function cancel(array $account, array $invoice, string $reason): array;
}
