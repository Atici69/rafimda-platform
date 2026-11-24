<?php

/** Herkese açık metinlerde kişisel/iletişim bilgisini gizler (soru-cevap, değerlendirme). Kaynak kayıt değişmez; yalnızca gösterim maskelenir. */
final class RvPrivacy
{
	private const PATTERNS = [
		'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu' => '[e-posta gizlendi]',
		'/\bTR\s?\d{2}(?:\s?\d{4}){5}\s?\d{2}\b/iu' => '[IBAN gizlendi]',
		'/(?<!\d)(?:\+?90[\s\-]?)?0?5\d{2}[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?!\d)/u' => '[telefon gizlendi]',
		'/(?<!\d)0?[2-4]\d{2}[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?!\d)/u' => '[telefon gizlendi]',
		'/(?<!\d)[1-9]\d{10}(?!\d)/u' => '[numara gizlendi]',
		'/\b(?:https?:\/\/|www\.)\S+/iu' => '[bağlantı gizlendi]',
	];

	public static function mask(string $text): string
	{
		return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
	}

	public static function containsPrivate(string $text): bool
	{
		return self::mask($text) !== $text;
	}
}
