<p><a href="{$listUrl}">← Ürün listesi</a></p>
{if $formMessage}<div class="rv-alert danger">{$formMessage}</div>{/if}
<form method="post" enctype="multipart/form-data" class="rv-card">
	<h3>{if $isEdit}Ürünü düzenle{else}Yeni ürün{/if}</h3>
	<input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="{if $isEdit}update{else}create{/if}">
	{if $isEdit}<input type="hidden" name="id_product" value="{$idProduct}">{/if}
	<label>Ürün adı *</label><input type="text" name="product_name" value="{$form.product_name|default:''}" maxlength="128" required>
	{if $errors.product_name|default:''}<div class="err">{$errors.product_name}</div>{/if}
	<div class="rv-row2">
		<div><label>Kategori *</label><select name="id_category"><option value="">Seçin</option>
			{foreach $categories as $c}<option value="{$c.id_category}"{if $c.id_category == ($form.id_category|default:0)} selected{/if}>{$c.category_name}</option>{/foreach}</select>
			{if $errors.id_category|default:''}<div class="err">{$errors.id_category}</div>{/if}</div>
		<div><label>Marka *</label><select name="id_brand"><option value="">Seçin</option>
			{foreach $brands as $b}<option value="{$b.id_brand}"{if $b.id_brand == ($form.id_brand|default:0)} selected{/if}>{$b.brand_name}</option>{/foreach}</select>
			{if $errors.id_brand|default:''}<div class="err">{$errors.id_brand}</div>{/if}</div>
	</div>
	<div class="rv-row3">
		<div><label>Satış fiyatı (₺) *</label><input type="text" name="price" value="{$form.price|default:''}" inputmode="decimal">{if $errors.price|default:''}<div class="err">{$errors.price}</div>{/if}</div>
		<div><label>Eski fiyat (₺)</label><input type="text" name="old_price" value="{$form.old_price|default:''}" inputmode="decimal"></div>
		<div><label>KDV %</label><input type="text" name="vat" value="{$form.vat|default:''}" inputmode="decimal"></div>
		<div><label>Stok</label><input type="text" name="stock" value="{$form.stock|default:0}" inputmode="decimal">{if $errors.stock|default:''}<div class="err">{$errors.stock}</div>{/if}</div>
		<div><label>Stok kodu</label><input type="text" name="stock_code" value="{$form.stock_code|default:''}"></div>
		<div><label>Barkod</label><input type="text" name="barcode" value="{$form.barcode|default:''}"></div>
	</div>
	<label>Kısa açıklama</label><textarea name="short_description" rows="2">{$form.short_description|default:''}</textarea>
	<label>Açıklama</label><textarea name="description" rows="6">{$form.description|default:''}</textarea>
	<label style="font-weight:400"><input type="checkbox" name="active" value="1"{if $form.active|default:0} checked{/if}> Ürün satışta (aktif)</label>

	<h3 style="margin-top:22px">Varyantlar</h3>
	<p style="color:var(--rv-muted);font-size:13px">Renk/beden gibi seçenekler. Varyant eklerseniz toplam stok varyant stoklarından hesaplanır.</p>
	<input type="hidden" name="variations_present" value="1">
	<div id="rv-vars">
	{foreach $variations as $i => $v}
		<div class="rv-var rv-card" style="padding:12px;background:#fafbfd"><div class="rv-row3">
			<div><label>Seçenek 1 adı</label><input type="text" name="variations[{$i}][option1_name]" value="{$v.option1_name|default:'Renk'}"></div>
			<div><label>Seçenek 1 değeri</label><input type="text" name="variations[{$i}][option1_value]" value="{$v.option1_value|default:''}"></div>
			<div><label>SKU</label><input type="text" name="variations[{$i}][sku]" value="{$v.sku|default:''}"></div>
			<div><label>Seçenek 2 adı</label><input type="text" name="variations[{$i}][option2_name]" value="{$v.option2_name|default:'Beden'}"></div>
			<div><label>Seçenek 2 değeri</label><input type="text" name="variations[{$i}][option2_value]" value="{$v.option2_value|default:''}"></div>
			<div><label>Barkod</label><input type="text" name="variations[{$i}][barcode]" value="{$v.barcode|default:''}"></div>
			<div><label>Fiyat (boş = ürün fiyatı)</label><input type="text" name="variations[{$i}][price]" value="{$v.price|default:''}"></div>
			<div><label>Stok</label><input type="text" name="variations[{$i}][stock]" value="{$v.stock|default:0}"></div>
			<div><label>&nbsp;</label><label style="font-weight:400"><input type="checkbox" name="variations[{$i}][active]" value="1" checked> Aktif</label></div>
		</div></div>
	{/foreach}
	</div>
	<button type="button" class="rv-btn ghost sm" id="rv-add-var">+ Varyant ekle</button>
	<template id="rv-var-tpl"><div class="rv-var rv-card" style="padding:12px;background:#fafbfd"><div class="rv-row3">
		<div><label>Seçenek 1 adı</label><input type="text" name="variations[__N__][option1_name]" value="Renk"></div>
		<div><label>Seçenek 1 değeri</label><input type="text" name="variations[__N__][option1_value]"></div>
		<div><label>SKU</label><input type="text" name="variations[__N__][sku]"></div>
		<div><label>Seçenek 2 adı</label><input type="text" name="variations[__N__][option2_name]" value="Beden"></div>
		<div><label>Seçenek 2 değeri</label><input type="text" name="variations[__N__][option2_value]"></div>
		<div><label>Barkod</label><input type="text" name="variations[__N__][barcode]"></div>
		<div><label>Fiyat</label><input type="text" name="variations[__N__][price]"></div>
		<div><label>Stok</label><input type="text" name="variations[__N__][stock]" value="0"></div>
		<div><label>&nbsp;</label><label style="font-weight:400"><input type="checkbox" name="variations[__N__][active]" value="1" checked> Aktif</label></div>
	</div></div></template>

	<h3 style="margin-top:22px">Görseller</h3>
	<input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
	<p style="color:var(--rv-muted);font-size:12px">En fazla 10 görsel, her biri 5 MB'a kadar. Sunucu tarafında içerik doğrulanır.</p>

	<p style="margin-top:18px"><button class="rv-btn" type="submit">Kaydet</button> <a class="rv-btn ghost" href="{$listUrl}">Vazgeç</a></p>
</form>

{if $isEdit}
<div class="rv-card"><h3>Mevcut görseller</h3>
	{if $images}<div class="rv-imgs">{foreach $images as $im}
		<figure><img class="{if $im.cover}cover{/if}" src="{$im.url|default:''}" alt="">
		{if $can.edit}<form method="post" style="display:flex;gap:4px;justify-content:center;margin-top:4px"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="id_product" value="{$idProduct}"><input type="hidden" name="id_image" value="{$im.id_image}">
			{if !$im.cover}<button class="rv-btn ghost sm" name="action" value="set_cover">Kapak</button>{/if}<button class="rv-btn danger sm" name="action" value="delete_image" data-confirm="Görsel silinsin mi?">Sil</button></form>{/if}
		</figure>{/foreach}</div>{else}<p style="color:var(--rv-muted)">Görsel yok.</p>{/if}
</div>
{if $can.edit}
<div class="rv-card"><h3>Hızlı fiyat / stok</h3>
	<form method="post" class="rv-toolbar"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="quick"><input type="hidden" name="id_product" value="{$idProduct}">
		<input type="text" name="price" value="{$form.price|default:''}" style="max-width:140px"><input type="text" name="stock" value="{$form.stock|default:0}" style="max-width:120px">
		<button class="rv-btn navy sm" type="submit">Güncelle</button></form>
</div>
{/if}
{if $can.delete}
<form method="post" class="rv-card"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="delete"><input type="hidden" name="id_product" value="{$idProduct}">
	<h3>Ürünü sil</h3><p style="color:var(--rv-muted)">Bu işlem geri alınamaz.</p><button class="rv-btn danger" type="submit" data-confirm="Ürün kalıcı olarak silinecek. Emin misiniz?">Ürünü sil</button></form>
{/if}
{/if}
