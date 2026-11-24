<div class="rv-card">
	<div class="rv-toolbar">
		<form method="get" action="{$rvDomain}satici-panel" class="rv-toolbar grow" style="margin:0">
			<input type="hidden" name="s" value="products">
			<input type="text" name="q" value="{$q}" placeholder="Ürün adı, stok kodu, barkod" style="max-width:280px">
			<select name="state" style="width:auto"><option value="">Tümü</option><option value="active"{if $state == 'active'} selected{/if}>Aktif</option><option value="passive"{if $state == 'passive'} selected{/if}>Pasif</option><option value="lowstock"{if $state == 'lowstock'} selected{/if}>Düşük stok</option></select>
			<button class="rv-btn ghost sm" type="submit">Filtrele</button>
		</form>
		{if $can.create}<a class="rv-btn" href="{$newUrl}">+ Yeni ürün</a>{/if}
	</div>
	<form method="post" id="rv-bulk"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="bulk">
	{if $can.edit || $can.delete}
	<div class="rv-toolbar">
		<select name="bulk_action" style="width:auto"><option value="activate">Aktif yap</option><option value="deactivate">Pasif yap</option>{if $can.delete}<option value="delete">Sil</option>{/if}</select>
		<button class="rv-btn ghost sm" type="submit" data-confirm="Seçili ürünlere işlem uygulansın mı?">Toplu işlem uygula</button>
		<span style="color:var(--rv-muted)">{$total} ürün</span>
	</div>
	{/if}
	<table class="rv-resp"><thead><tr><th><input type="checkbox" id="rv-check-all"></th><th></th><th>Ürün</th><th>Kategori / Marka</th><th>Fiyat</th><th>Stok</th><th>Durum</th><th></th></tr></thead><tbody>
	{foreach $products as $p}<tr>
		<td><input type="checkbox" name="ids[]" value="{$p.id_product}"></td>
		<td>{if $p.image_url}<img class="thumb" src="{$p.image_url}" alt="">{else}<span class="thumb" style="display:inline-block"></span>{/if}</td>
		<td data-l="Ürün"><a href="{$rvDomain}satici-panel?s=products&amp;a=edit&amp;id={$p.id_product}"><strong>{$p.product_name}</strong></a><br><small style="color:var(--rv-muted)">{$p.stock_code}</small></td>
		<td data-l="Kategori">{$p.category_name}<br><small style="color:var(--rv-muted)">{$p.brand_name}</small></td>
		<td data-l="Fiyat">{$p.price|string_format:"%.2f"} ₺</td>
		<td data-l="Stok">{$p.stock|string_format:"%g"}</td>
		<td data-l="Durum">{if $p.active}<span class="pill ok">Aktif</span>{else}<span class="pill off">Pasif</span>{/if}</td>
		<td><a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=products&amp;a=edit&amp;id={$p.id_product}">Düzenle</a></td>
	</tr>{foreachelse}<tr><td colspan="8" style="text-align:center;color:var(--rv-muted);padding:30px">Henüz ürün yok. {if $can.create}<a href="{$newUrl}">İlk ürününüzü ekleyin.</a>{/if}</td></tr>{/foreach}
	</tbody></table></form>
	{if $pages > 1}<div class="rv-pager">
		{if $page > 1}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=products&amp;page={$page-1}&amp;q={$q|escape:'url'}&amp;state={$state|escape:'url'}">‹ Önceki</a>{/if}
		<span style="padding:5px 8px">{$page} / {$pages}</span>
		{if $page < $pages}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=products&amp;page={$page+1}&amp;q={$q|escape:'url'}&amp;state={$state|escape:'url'}">Sonraki ›</a>{/if}
	</div>{/if}
</div>
