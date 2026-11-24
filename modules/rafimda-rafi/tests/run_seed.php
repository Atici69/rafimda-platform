<?php
// Test verisi: açıkça TEST amaçlı örnek ürün/satıcılar (yalnızca bellek içi SQLite)
if (PHP_SAPI !== 'cli') { exit; }
function seed(): array {
	rv_fresh_db(); rv_apply_migrations();
	$db = $GLOBALS['db'];
	foreach (['short_description TEXT DEFAULT ""', 'description TEXT DEFAULT ""', 'product_link TEXT DEFAULT ""'] as $c) { $db->exec('ALTER TABLE products ADD COLUMN ' . $c); }
	$db->exec('ALTER TABLE brands ADD COLUMN brand_link TEXT DEFAULT ""'); $db->exec('ALTER TABLE categories ADD COLUMN category_link TEXT DEFAULT ""');
	$db->exec("INSERT INTO categories (id_category, category_name, active, category_link) VALUES (3, 'Elektronik', 1, 'elektronik')");
	$db->exec("INSERT INTO brands (id_brand, brand_name, active) VALUES (2, 'Jabra', 1), (3, 'Xiaomi', 1)");
	$mk = function ($name, $brand, $price, $old, $stock, $active = 1, $desc = '') use ($db) { $db->prepare('INSERT INTO products (product_name, id_category, id_brand, price, old_price, stock, stock_code, barcode, active, short_description, description) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$name, 3, $brand, $price, $old, $stock, 'S', '', $active, '', $desc]); return (int) $db->lastInsertId(); };
	$ids = [
		'jabra' => $mk('Jabra Evolve2 Kulaklık', 2, 549, 0, 30, 1, '<ul><li>Aktif gürültü engelleme</li><li>USB-A</li></ul>'),
		'xkul' => $mk('Xiaomi Buds Kulaklık', 3, 899, 1099, 3),
		'pahali' => $mk('Stüdyo Kulaklık Pro', 2, 4500, 0, 10),
		'tukendi' => $mk('Eski Kulaklık', 2, 300, 0, 0),
		'pasif' => $mk('Pasif Kulaklık', 2, 100, 0, 10, 0),
		'askida' => $mk('Askıdaki Satıcı Kulaklık', 2, 150, 0, 10),
		'bekleyen' => $mk('Onay Bekleyen Kulaklık', 2, 160, 0, 10),
		'saat' => $mk('Xiaomi Redmi Watch 5 Akıllı Saat', 3, 600, 0, 20),
	];
	$v = function ($name, $slug, $status) use ($db) { DB::insert('rafimda_vendors', ['owner_user_id' => mt_rand(100, 999), 'store_name' => $name, 'store_slug' => $slug, 'status' => $status, 'commission_rate' => 10]); return (int) $db->lastInsertId(); };
	$va = $v('Ses Dünyası', 'ses-dunyasi', 'active'); $vs = $v('Askıda Mağaza', 'askida', 'suspended');
	foreach ([['jabra', $va, 'approved'], ['askida', $vs, 'approved'], ['bekleyen', $va, 'pending']] as [$k, $vid, $mod]) { DB::insert('rafimda_vendor_products', ['id_product' => $ids[$k], 'id_vendor' => $vid, 'moderation' => $mod]); }
	DB::insert('rafimda_store_reviews', ['id_vendor' => $va, 'id_user' => 1, 'id_order' => 1, 'rating' => 4, 'comment' => 'iyi', 'status' => 'approved']);
	DB::insert('rafimda_store_reviews', ['id_vendor' => $va, 'id_user' => 2, 'id_order' => 2, 'rating' => 5, 'comment' => 'çok iyi', 'status' => 'approved']);
	DB::insert('rafimda_store_reviews', ['id_vendor' => $va, 'id_user' => 3, 'id_order' => 3, 'rating' => 1, 'comment' => 'bekliyor', 'status' => 'pending']);
	return $ids;
}
