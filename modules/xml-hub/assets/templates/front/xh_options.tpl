{foreach ['create_new' => 'Yeni ürünleri ekle', 'update_existing' => 'Mevcut ürünleri güncelle', 'sync_price' => 'Fiyatı güncelle', 'sync_stock' => 'Stoğu güncelle', 'sync_description' => 'Açıklamayı güncelle', 'sync_images' => 'Görselleri senkronize et (aşağıdaki moda göre)', 'sync_cargo' => 'Kargo ücretini kaydet (xh_product_meta)', 'create_variants' => 'Mevcut ürüne yeni varyantları ekle'] as $k => $l}
<label class="chk"><input type="hidden" name="opt[{$k|escape}]" value="0"><input type="checkbox" name="opt[{$k|escape}]" value="1"{if $opt[$k]} checked{/if}> {$l|escape}</label>
{/foreach}
<div class="grid g2">
	<div><label>Fiyat artışı (%)</label><input type="number" step="0.01" name="opt[price_markup_percent]" value="{$opt.price_markup_percent|escape}"></div>
	<div><label>Sabit ekleme (₺)</label><input type="number" step="0.01" name="opt[price_add]" value="{$opt.price_add|escape}"></div>
	<div><label>Varsayılan kategori</label><select name="opt[default_category]"><option value="0">—</option>{foreach $categories as $c}<option value="{$c.id_category|escape}"{if $c.id_category == $opt.default_category} selected{/if}>{$c.category_name|escape}</option>{/foreach}</select></div>
	<div><label>Varsayılan marka</label><select name="opt[default_brand]"><option value="0">—</option>{foreach $brands as $b}<option value="{$b.id_brand|escape}"{if $b.id_brand == $opt.default_brand} selected{/if}>{$b.brand_name|escape}</option>{/foreach}</select></div>
	<div><label>Görsel modu</label><select name="opt[image_mode]"><option value="if_empty"{if $opt.image_mode != 'add_new'} selected{/if}>Yalnızca görseli olmayan ürüne ekle</option><option value="add_new"{if $opt.image_mode == 'add_new'} selected{/if}>Yeni görsel adreslerini ekle (eskiler silinmez)</option></select></div>
	<div><label>Ürün başına en çok görsel</label><input type="number" min="0" max="10" name="opt[max_images]" value="{$opt.max_images|escape}"></div>
</div>

<details class="xh-category-margins">
	<summary>Kategoriye özel kâr oranları (isteğe bağlı)</summary>
	<p>Burada tanımlanan oran, genel kâr oranını yalnızca seçilen kategori için geçersiz kılar. Boş bırakılan kategoriler genel oranı kullanır.</p>
	<div class="table-responsive"><table class="table">
		<thead><tr><th>Kategori</th><th>Ek kâr (%)</th><th>Sabit ekleme (₺)</th></tr></thead>
		<tbody>
		{foreach $categories as $c}
		<tr>
			<td>{$c.category_name|escape}</td>
			<td><input type="number" step="0.01" name="opt[category_markup][{$c.id_category|escape}][percent]" value="{$opt.category_markup[$c.id_category].percent|default:''|escape}" placeholder="Genel oran"></td>
			<td><input type="number" step="0.01" name="opt[category_markup][{$c.id_category|escape}][add]" value="{$opt.category_markup[$c.id_category].add|default:''|escape}" placeholder="0"></td>
		</tr>
		{/foreach}
		</tbody>
	</table></div>
</details>
