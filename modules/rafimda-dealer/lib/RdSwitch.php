<?php

/**
 * Bayilik ana anahtarı. İlk lansmanda bayilik PASİFTİR (ayar yoksa da pasif).
 * Pasifken: bayi rotaları 404 verir, ana sayfa/menü/kayıt bağlantıları gizlenir, sipariş kancaları komisyon üretmez,
 * yönetici panelinde yalnızca "Şimdilik Pasif" ekranı ve salt okunur kayıt sayıları görünür. Tablolar ve veriler KORUNUR.
 * Etkinleştirme: yalnızca yönetici panelinde onay ifadesi yazılarak (kullanıcının açık yazılı onayıyla) yapılır.
 */
final class RdSwitch
{
	public const SETTING = 'RAFIMDA_DEALER_ENABLED';
	public const CONFIRM_PHRASE = 'BAYILIGI ETKINLESTIR';
	public const PASSIVE_LABEL = 'Şimdilik Pasif – Gelecekte Etkinleştirilecek';

	public static function enabled(): bool
	{
		try {
			return class_exists('Settings') && (string) Settings::get(self::SETTING) === '1';
		} catch (Throwable $e) {
			return false;
		}
	}

	/** @return array{success:bool,message:string} */
	public static function setEnabled(bool $on, string $phrase, int $adminId): array
	{
		if ($on && trim($phrase) !== self::CONFIRM_PHRASE) {
			return ['success' => false, 'message' => 'Etkinleştirmek için onay ifadesini aynen yazın: ' . self::CONFIRM_PHRASE];
		}

		Settings::set(self::SETTING, $on ? '1' : '0');

		if (class_exists('RdAudit')) {
			RdAudit::log(null, $on ? 'dealer_system_enabled' : 'dealer_system_disabled', 'system', 0, [], $adminId);
		}

		return ['success' => true, 'message' => $on ? 'Bayilik sistemi etkinleştirildi.' : 'Bayilik sistemi pasife alındı. Veriler korunuyor.'];
	}
}
