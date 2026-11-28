<?php
require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label\n"; }
}

xh_fresh_db();

echo "== Kategori bazlı fiyat kuralları ==\n";
$opt = XhRepo::sanitizeOptions([
    'price_markup_percent' => '10',
    'price_add' => '5',
    'category_markup' => [
        '2' => ['percent' => '20,5', 'add' => '2,50'],
        '-1' => ['percent' => '999', 'add' => '1'],
        '3' => 'invalid',
        '4' => ['percent' => '9999', 'add' => '-999999'],
    ],
]);
check($opt['price_markup_percent'] === 10.0 && $opt['price_add'] === 5.0, 'genel kâr oranı korunur');
check($opt['category_markup'][2]['percent'] === 20.5 && $opt['category_markup'][2]['add'] === 2.5, 'kategori yüzdesi ve sabit tutar Türkçe ondalıkla okunur');
check(!isset($opt['category_markup'][-1]) && !isset($opt['category_markup'][3]), 'geçersiz kategori anahtarları reddedilir');
check($opt['category_markup'][4]['percent'] === 500.0 && $opt['category_markup'][4]['add'] === -100000.0, 'kategori kuralları güvenli sınırlar içinde tutulur');

$mapping = [
    'fields' => [
        'name' => 'Name', 'sku' => 'Sku', 'price' => 'Price', 'stock' => 'Qty',
        'brand' => 'Brand', 'category' => 'Category'
    ],
    'brand_map' => ['MarkaB' => 2],
    'category_map' => ['Ayakkabı' => 2],
];
$raw = ['Name' => 'Test Ayakkabı', 'Sku' => 'TEST-001', 'Price' => '100,00', 'Qty' => '8', 'Brand' => 'MarkaB', 'Category' => 'Ayakkabı'];
$row = XhMapper::apply($raw, $mapping, $opt, [XhImporter::class, 'lookup']);
check($row['errors'] === [], 'ürün alanları kategori ve marka eşleştirmesiyle doğrulanır');
check(abs((float) $row['price'] - 123.0) < 0.001, 'kategori kuralı genel %10 + 5 TL yerine %20,5 + 2,50 TL uygular (100 → 123)');
check((int) $row['id_category'] === 2 && (int) $row['id_brand'] === 2, 'eşleştirilen kategori ve marka ID değerleri korunur');

$mapping['category_map'] = ['Giyim' => 1];
$raw['Category'] = 'Giyim';
$row2 = XhMapper::apply($raw, $mapping, $opt, [XhImporter::class, 'lookup']);
check(abs((float) $row2['price'] - 115.0) < 0.001, 'kategori kuralı olmayan ürün genel %10 + 5 TL kuralını kullanır (100 → 115)');

echo "\n==== SONUÇ: $pass geçti, $fail başarısız ====\n";
exit($fail ? 1 : 0);
