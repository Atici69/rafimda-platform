<?php

/**
 * TEST / SİMÜLASYON bağdaştırıcısı. GİB'e veya herhangi bir entegratöre BAĞLANMAZ; mali değeri olan belge üretmez.
 * Fatura numaraları "TST" serisindedir ve PDF'lerde "TEST – MALİ DEĞERİ YOKTUR" yazar. Uçtan uca akışı doğrulamak içindir.
 * Kurallar (tahmin edilebilir test için): VKN "0000000000" e-Fatura kullanıcısı sayılır; alıcı adı "RED" içerirse belge reddedilir.
 */
final class RiTestAdapter implements RiAdapter
{
	public function code(): string
	{
		return 'test';
	}

	public function label(): string
	{
		return 'Test / simülasyon (gerçek gönderim yok)';
	}

	public function isImplemented(): bool
	{
		return true;
	}

	public function testConnection(array $account): array
	{
		return ['success' => true, 'message' => 'Test bağdaştırıcısı hazır (gerçek entegratöre bağlanılmadı).'];
	}

	public function isEInvoiceUser(array $account, string $taxNo): ?bool
	{
		return $taxNo === '0000000000';
	}

	public function send(array $account, array $invoice): array
	{
		if (stripos((string) $invoice['buyer_name'], 'RED') !== false) {
			return ['success' => false, 'status' => 'rejected', 'message' => 'TEST: alıcı belgeyi reddetti (simülasyon).'];
		}

		$seq = str_pad((string) (int) $invoice['id_invoice'], 9, '0', STR_PAD_LEFT);
		$h = bin2hex(random_bytes(16));
		$uuid = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);

		return ['success' => true, 'status' => 'accepted', 'ettn' => $uuid, 'invoice_no' => 'TST' . date('Y') . $seq, 'message' => 'TEST: belge simülasyonda kabul edildi.'];
	}

	public function status(array $account, array $invoice): array
	{
		return ['success' => true, 'status' => (string) $invoice['status'], 'message' => 'TEST: durum değişmedi.'];
	}

	public function cancel(array $account, array $invoice, string $reason): array
	{
		return ['success' => true, 'message' => 'TEST: iptal simülasyonda kaydedildi.'];
	}
}
