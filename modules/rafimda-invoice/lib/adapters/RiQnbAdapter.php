<?php

/**
 * QNB eSolutions (eski adıyla QNB eFinans) bağdaştırıcısı — İSKELET. Gerçek gönderim UYGULANMADI.
 * Gerçek entegrasyon için: entegratör sözleşmesi, test ortamı kullanıcı bilgileri ve güncel servis dokümantasyonu (SOAP/REST uç noktaları,
 * UBL-TR şeması, imzalama) gerekir. Bunlar sağlanmadan bu sınıf gönderim yapmaz ve "hazır değil" döner; çalışıyormuş gibi görünmez.
 */
final class RiQnbAdapter implements RiAdapter
{
	private const NOT_READY = 'QNB eSolutions bağdaştırıcısı henüz uygulanmadı: entegratör sözleşmesi, test hesabı ve güncel API dokümanı gerekli.';

	public function code(): string
	{
		return 'qnb';
	}

	public function label(): string
	{
		return 'QNB eSolutions (hazır değil — sözleşme ve test hesabı bekleniyor)';
	}

	public function isImplemented(): bool
	{
		return false;
	}

	public function testConnection(array $account): array
	{
		return ['success' => false, 'message' => self::NOT_READY];
	}

	public function isEInvoiceUser(array $account, string $taxNo): ?bool
	{
		return null;
	}

	public function send(array $account, array $invoice): array
	{
		return ['success' => false, 'status' => 'error', 'message' => self::NOT_READY];
	}

	public function status(array $account, array $invoice): array
	{
		return ['success' => false, 'status' => (string) $invoice['status'], 'message' => self::NOT_READY];
	}

	public function cancel(array $account, array $invoice, string $reason): array
	{
		return ['success' => false, 'message' => self::NOT_READY];
	}
}
