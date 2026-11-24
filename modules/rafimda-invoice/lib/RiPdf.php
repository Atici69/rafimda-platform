<?php

/**
 * Bağımlılıksız küçük PDF yazıcı (fatura görüntüsü). Standart Helvetica + Türkçe harfler için /Differences kodlaması.
 * Gerçek e-Fatura/e-Arşiv PDF'i entegratörden gelir; bu sınıf TEST ortamı ve taslak önizleme belgesi üretir.
 */
final class RiPdf
{
	private const MAP = ['Ğ' => 128, 'ğ' => 129, 'İ' => 130, 'ı' => 131, 'Ş' => 132, 'ş' => 133, 'ç' => 0xE7, 'Ç' => 0xC7, 'ö' => 0xF6, 'Ö' => 0xD6, 'ü' => 0xFC, 'Ü' => 0xDC,
		'â' => 0xE2, 'Â' => 0xC2, 'î' => 0xEE, 'û' => 0xFB, 'é' => 0xE9, '·' => 0xB7, '–' => 0x96, '—' => 0x97, '’' => 0x92, '“' => 0x93, '”' => 0x94, '%' => 0x25];

	/** @var list<string> */
	private array $ops = [];

	public static function enc(string $s): string
	{
		$s = str_replace(['₺', "\r", "\n", "\t"], ['TL', ' ', ' ', ' '], $s);
		$out = '';

		foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
			if (isset(self::MAP[$ch])) {
				$out .= chr(self::MAP[$ch]);
			} elseif (strlen($ch) === 1 && ord($ch) >= 32 && ord($ch) < 127) {
				$out .= $ch;
			} else {
				$out .= '?';
			}
		}

		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $out);
	}

	public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'left', string $rgb = '0 0 0'): self
	{
		if ($align !== 'left') {
			$w = mb_strlen($s) * $size * ($bold ? 0.56 : 0.52);
			$x = $align === 'right' ? $x - $w : $x - $w / 2;
		}

		$this->ops[] = sprintf('%s rg BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET', $rgb, $bold ? 'F2' : 'F1', $size, $x, $y, self::enc($s));

		return $this;
	}

	public function rect(float $x, float $y, float $w, float $h, string $rgb): self
	{
		$this->ops[] = sprintf('%s rg %.2F %.2F %.2F %.2F re f', $rgb, $x, $y, $w, $h);

		return $this;
	}

	public function line(float $x1, float $y1, float $x2, float $y2, string $rgb = '0.85 0.87 0.9'): self
	{
		$this->ops[] = sprintf('%s RG 0.6 w %.2F %.2F m %.2F %.2F l S', $rgb, $x1, $y1, $x2, $y2);

		return $this;
	}

	public function output(): string
	{
		$stream = implode("\n", $this->ops);
		$diff = '/Differences [128 /Gbreve /gbreve /Idotaccent /dotlessi /Scedilla /scedilla]';
		$objs = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
			'<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding ' . $diff . ' >> >>',
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding ' . $diff . ' >> >>',
		];
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];

		foreach ($objs as $i => $o) {
			$offsets[] = strlen($pdf);
			$pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";

		foreach ($offsets as $off) {
			$pdf .= sprintf("%010d 00000 n \n", $off);
		}

		return $pdf . 'trailer' . "\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}

	/** Fatura belgesi (tek sayfa; çok kalemde ilk 22 kalem + özet). */
	public static function invoice(array $inv, array $issuer): string
	{
		$p = new self();
		$test = ($inv['environment'] ?? 'test') !== 'live';
		$money = static fn($v) => number_format((float) $v, 2, ',', '.') . ' TL';
		$p->rect(0, 792, 595, 50, '0.043 0.145 0.27');
		$p->text(40, 812, 'Rafımda', 18, true, 'left', '1 1 1');
		$typeLabel = ['efatura' => 'e-Fatura', 'earsiv' => 'e-Arşiv Fatura', 'paper' => 'Manuel Fatura'][$inv['doc_type']] ?? 'Fatura';
		$p->text(555, 812, $typeLabel . ($inv['kind'] === 'return' ? ' (İade)' : ''), 14, true, 'right', '1 0.48 0');

		if ($test) {
			$p->rect(40, 760, 515, 24, '1 0.95 0.8');
			$p->text(297, 768, 'TEST / TASLAK – MALİ DEĞERİ YOKTUR – GİB\'E GÖNDERİLMEMİŞTİR', 11, true, 'center', '0.6 0.25 0');
		}

		$y = 735;
		$p->text(40, $y, 'Düzenleyen', 9, true, 'left', '0.42 0.46 0.53');
		$p->text(310, $y, 'Alıcı', 9, true, 'left', '0.42 0.46 0.53');
		$rowsL = [$issuer['name'] ?? '', 'VD: ' . ($issuer['tax_office'] ?? '') . '  VKN/TCKN: ' . ($issuer['tax_no'] ?? ''), $issuer['address'] ?? ''];
		$rowsR = [$inv['buyer_name'], ($inv['buyer_tax_no'] !== '' ? 'VD: ' . $inv['buyer_tax_office'] . '  VKN/TCKN: ' . $inv['buyer_tax_no'] : 'Nihai tüketici'), $inv['buyer_address']];

		foreach ([0, 1, 2] as $i) {
			$p->text(40, $y - 14 - $i * 13, mb_substr((string) $rowsL[$i], 0, 52), 9.5, $i === 0);
			$p->text(310, $y - 14 - $i * 13, mb_substr((string) $rowsR[$i], 0, 52), 9.5, $i === 0);
		}

		$y = 670;
		$p->line(40, $y, 555, $y);
		$meta = ['Fatura no' => $inv['invoice_no'] !== '' ? $inv['invoice_no'] : '(gönderimde verilir)', 'ETTN' => $inv['ettn'] !== '' ? $inv['ettn'] : '—', 'Tarih' => date('d.m.Y', strtotime((string) ($inv['sent_at'] ?: $inv['date_add']))), 'Sipariş' => $inv['id_order'] ? '#' . $inv['id_order'] : '—'];
		$x = 40;

		foreach ($meta as $k => $v) {
			$p->text($x, $y - 14, $k, 8, true, 'left', '0.42 0.46 0.53');
			$p->text($x, $y - 27, (string) $v, 8.5);
			$x += $k === 'ETTN' ? 190 : 108;
		}

		$y = 620;
		$p->rect(40, $y - 4, 515, 18, '0.95 0.96 0.98');
		foreach ([['Ürün / hizmet', 46, 'left'], ['Adet', 300, 'right'], ['Birim (KDV hariç)', 385, 'right'], ['KDV %', 425, 'right'], ['KDV tutarı', 487, 'right'], ['Toplam', 552, 'right']] as [$h, $hx, $al]) {
			$p->text($hx, $y + 1, $h, 8, true, $al);
		}

		$lines = json_decode((string) $inv['lines'], true) ?: [];
		$y -= 20;

		foreach (array_slice($lines, 0, 22) as $l) {
			$p->text(46, $y, mb_substr((string) $l['name'], 0, 42), 9);
			$p->text(300, $y, rtrim(rtrim(number_format((float) $l['qty'], 3, ',', ''), '0'), ','), 9, false, 'right');
			$p->text(385, $y, $money($l['unit_net']), 9, false, 'right');
			$p->text(425, $y, '%' . rtrim(rtrim(number_format((float) $l['vat_rate'], 2, ',', ''), '0'), ','), 9, false, 'right');
			$p->text(487, $y, $money($l['vat']), 9, false, 'right');
			$p->text(552, $y, $money($l['gross']), 9, false, 'right');
			$p->line(40, $y - 5, 555, $y - 5, '0.93 0.94 0.96');
			$y -= 18;
		}

		if (count($lines) > 22) {
			$p->text(46, $y, '… ve ' . (count($lines) - 22) . ' kalem daha (tam liste entegratör belgesinde)', 8.5);
			$y -= 18;
		}

		$y -= 10;
		foreach (['Ara toplam (KDV hariç)' => $inv['net_total'], 'Toplam KDV' => $inv['vat_total'], 'Genel toplam' => $inv['gross_total']] as $k => $v) {
			$bold = $k === 'Genel toplam';
			$p->text(450, $y, $k, 9.5, $bold, 'right');
			$p->text(552, $y, $money($v), 9.5, $bold, 'right', $bold ? '1 0.48 0' : '0 0 0');
			$y -= 16;
		}

		$p->text(40, 60, 'Bu belge Rafımda fatura modülü tarafından üretilmiştir. ' . ($test ? 'TEST ortamı: yasal fatura yerine geçmez.' : 'Yasal belge entegratör kayıtlarıdır.'), 7.5, false, 'left', '0.42 0.46 0.53');

		return $p->output();
	}
}
