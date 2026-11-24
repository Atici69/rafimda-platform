<?php

/**
 * Satıcı rol/izin matrisi. Sunucu tarafında her istekte RvContext::can() ile denetlenir.
 */
class RvPerm
{
	public const ROLES = [
		'owner'   => 'Mağaza Sahibi',
		'manager' => 'Yönetici',
		'catalog' => 'Katalog Sorumlusu',
		'orders'  => 'Sipariş Sorumlusu',
		'viewer'  => 'Görüntüleyici',
	];

	/** Menü bölümü => izin */
	public const SECTION_PERMS = [
		'dashboard' => 'dashboard.view',
		'products'  => 'products.view',
		'xml'       => 'xml.view',
		'suppliers' => 'suppliers.view',
		'orders'    => 'orders.view',
		'marketplaces' => 'marketplaces.view',
		'stock'     => 'stock.view',
		'pricing'   => 'pricing.view',
		'customers' => 'customers.view',
		'campaigns' => 'campaigns.view',
		'reports'   => 'reports.view',
		'finance'   => 'finance.view',
		'invoices'  => 'invoices.view',
		'staff'     => 'staff.view',
		'subscription' => 'subscription.view',
		'store'     => 'store.view',
		'support'   => 'support.view',
		'settings'  => 'settings.view',
		'storefront' => 'store.view',
		'questions' => 'questions.view',
		'reviews'   => 'reviews.view',
		'notifications' => 'notifications.view',
		'social'    => 'social.view',
	];

	/** @return list<string> */
	public static function forRole(string $role): array
	{
		$view = [
			'dashboard.view', 'products.view', 'xml.view', 'suppliers.view', 'orders.view', 'marketplaces.view',
			'stock.view', 'pricing.view', 'customers.view', 'campaigns.view', 'reports.view', 'finance.view',
			'invoices.view', 'staff.view', 'subscription.view', 'store.view', 'support.view', 'settings.view',
			'questions.view', 'reviews.view', 'notifications.view', 'social.view',
		];

		switch ($role) {
			case 'owner':
				return array_merge($view, [
					'products.create', 'products.edit', 'products.delete', 'orders.manage',
					'xml.manage', 'marketplaces.manage', 'staff.manage', 'store.manage',
					'questions.answer', 'reviews.reply', 'support.manage', 'invoices.manage', 'social.manage',
				]);
			case 'manager':
				return array_merge($view, [
					'products.create', 'products.edit', 'products.delete', 'orders.manage', 'xml.manage', 'marketplaces.manage',
					'questions.answer', 'reviews.reply', 'support.manage', 'invoices.manage', 'social.manage',
				]);
			case 'catalog':
				return ['dashboard.view', 'products.view', 'products.create', 'products.edit', 'products.delete',
					'xml.view', 'xml.manage', 'stock.view', 'pricing.view', 'support.view', 'questions.view', 'questions.answer', 'notifications.view', 'social.view', 'social.manage'];
			case 'orders':
				return ['dashboard.view', 'products.view', 'orders.view', 'orders.manage', 'customers.view', 'support.view', 'support.manage', 'invoices.view', 'invoices.manage',
					'questions.view', 'questions.answer', 'reviews.view', 'reviews.reply', 'notifications.view'];
			case 'viewer':
				return ['dashboard.view', 'products.view', 'orders.view', 'support.view', 'questions.view', 'reviews.view', 'notifications.view', 'social.view'];
		}

		return [];
	}

	public static function roleAllows(string $role, string $perm): bool
	{
		return in_array($perm, self::forRole($role), true);
	}

	public static function isValidRole(string $role): bool
	{
		return isset(self::ROLES[$role]);
	}
}
