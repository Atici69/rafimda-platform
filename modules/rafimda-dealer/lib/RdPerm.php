<?php

/**
 * Bayi sistemi yetki matrisi (RBAC). Roller:
 *  - admin     : platform yöneticisi (Yönetim → Bayiler ekranı). FriSay çekirdeğinde ayrı yönetici rolü yoktur; yönetici tektir.
 *  - dealer    : ana bayi (id_parent = 0)
 *  - subdealer : alt bayi (id_parent > 0; yalnızca bir üst bayiye bağlı, 2 seviye)
 *  - guest     : oturumsuz / bayi olmayan müşteri (yalnızca başvuru ve giriş)
 * Her istekte sunucu tarafında RdPanel / RafimdaDealerModule tarafından denetlenir; arayüzde gizlemek tek başına güvenlik sayılmaz.
 * Muhasebe yetkileri BİLEREK yoktur (muhasebe modülü pasif).
 */
class RdPerm
{
	public const ROLES = ['admin' => 'Platform Yöneticisi', 'dealer' => 'Bayi', 'subdealer' => 'Alt Bayi', 'guest' => 'Ziyaretçi / Müşteri'];

	/** @var array<string,string> yetki => açıklama */
	public const PERMISSIONS = [
		// Başvuru
		'apply.submit' => 'Bayilik başvurusu yapar',
		// Bayi paneli (her bölüm = panel.<bölüm>)
		'panel.dashboard' => 'Genel Bakış',
		'panel.customers' => 'Kendi müşterilerini görür',
		'panel.subdealers' => 'Alt bayilerini görür, davet bağlantısı kullanır',
		'panel.earnings' => 'Kendi komisyon kayıtlarını görür',
		'panel.subscription' => 'Kendi aboneliğini görür',
		'panel.reports' => 'Kendi performans raporlarını görür',
		'panel.profile' => 'Profil ve referans bağlantısı',
		'earnings.export' => 'Kendi komisyonlarını CSV dışa aktarır',
		'subscription.request' => 'Paket talebi açar',
		'subdealers.manage' => 'Kendi alt bayisini durdurur / yeniden başlatır',
		// Platform yönetimi
		'dealers.review' => 'Başvuruları onaylar / reddeder',
		'dealers.suspend' => 'Bayiyi askıya alır / yeniden etkinleştirir',
		'dealers.rates' => 'Bayiye özel komisyon ve üst bayi payı oranı belirler',
		'plans.manage' => 'Bayi paketlerini tanımlar',
		'subscriptions.assign' => 'Bayiye paket atar / talebi sonuçlandırır',
		'commissions.moderate' => 'Komisyon kaydını onaylar / iptal eder',
		'customers.attach' => 'Müşteriyi elle bir bayiye atar',
		'settings.manage' => 'Varsayılan komisyon oranı ve migration',
		'reports.platform' => 'Platform genelindeki bayi raporlarını görür',
	];

	private const MATRIX = [
		'guest' => ['apply.submit'],
		'dealer' => ['panel.dashboard', 'panel.customers', 'panel.subdealers', 'panel.earnings', 'panel.subscription', 'panel.reports', 'panel.profile', 'earnings.export', 'subscription.request', 'subdealers.manage'],
		'subdealer' => ['panel.dashboard', 'panel.customers', 'panel.earnings', 'panel.subscription', 'panel.reports', 'panel.profile', 'earnings.export', 'subscription.request'],
		'admin' => ['dealers.review', 'dealers.suspend', 'dealers.rates', 'plans.manage', 'subscriptions.assign', 'commissions.moderate', 'customers.attach', 'settings.manage', 'reports.platform'],
	];

	/** Yönetim ekranı işlem adı => gereken yetki (rafimda-dealer.php handleAdminPost ile birebir eşleşir; test eder). */
	public const ADMIN_ACTIONS = [
		'migrate' => 'settings.manage',
		'dealer_status' => 'dealers.review',
		'dealer_rates' => 'dealers.rates',
		'plan_save' => 'plans.manage',
		'plan_assign' => 'subscriptions.assign',
		'commission_set' => 'commissions.moderate',
		'attach_customer' => 'customers.attach',
		'settings' => 'settings.manage',
	];

	/** @return list<string> */
	public static function forRole(string $role): array
	{
		return self::MATRIX[$role] ?? [];
	}

	public static function can(string $role, string $perm): bool
	{
		return in_array($perm, self::forRole($role), true);
	}

	/** Bayi kaydından rol: ana bayi mi alt bayi mi. @param array<string,mixed> $dealer */
	public static function roleOf(array $dealer): string
	{
		return (int) ($dealer['id_parent'] ?? 0) > 0 ? 'subdealer' : 'dealer';
	}
}
