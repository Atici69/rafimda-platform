<div class="rv-card">
	<form method="get" action="{$rvDomain}satici-panel" class="rv-toolbar" style="margin:0 0 10px">
		<input type="hidden" name="s" value="pricing">
		<input type="text" name="q" value="{$q}" placeholder="Ürün adı, stok kodu, barkod" style="max-width:280px"><button class="rv-btn ghost sm" type="submit">Ara</button>
		<span style="color:var(--rv-muted)">{$total} ürün</span>
	</form>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="price_save" id="rv-price-action">
	{if $can.edit}<div class="rv-toolbar" style="margin-bottom:10px">
		<label>Seçililere yüzde değişim: <input type="number" name="percent" step="0.1" min="-50" max="100" placeholder="örn. -10 veya 5" style="max-width:130px"></label>
		<button class="rv-btn ghost sm" type="submit" onclick="document.getElementById('rv-price-action').value='price_adjust'" data-confirm="Seçili ürünlerin fiyatı yüzde ile değiştirilsin mi?">Uygula</button>
	</div>{/if}
	<table class="rv-resp"><thead><tr>{if $can.edit}<th></th>{/if}<th>Ürün</th><th>Stok kodu</th><th>Fiyat</th><th>Liste fiyatı</th>{if $can.edit}<th>Yeni fiyat (₺)</th>{/if}</tr></thead><tbody>
	{foreach $rows as $p}<tr>
		{if $can.edit}<td>{if !$p.has_variants}<input type="checkbox" name="ids[]" value="{$p.id_product}">{/if}</td>{/if}
		<td data-l="Ürün"><a href="{$rvDomain}satici-panel?s=products&amp;a=edit&amp;id={$p.id_product}"><strong>{$p.product_name}</strong></a></td>
		<td data-l="Stok kodu">{$p.stock_code}</td>
		<td data-l="Fiyat">{$p.price|string_format:"%.2f"} ₺</td>
		<td data-l="Liste">{if $p.old_price > $p.price}<s>{$p.old_price|string_format:"%.2f"} ₺</s>{else}—{/if}</td>
		{if $can.edit}<td data-l="Yeni fiyat">{if $p.has_variants}<small style="color:var(--rv-muted)">Varyantlı</small>{else}<input type="text" inputmode="decimal" name="rows[{$p.id_product}][price]" placeholder="{$p.price|string_format:"%.2f"}" style="max-width:110px">{/if}</td>{/if}
	</tr>{foreachelse}<tr><td colspan="6" style="text-align:center;color:var(--rv-muted);padding:30px">Kayıt yok.</td></tr>{/foreach}
	</tbody></table>
	{if $can.edit && $rows}<p><button class="rv-btn" type="submit" onclick="document.getElementById('rv-price-action').value='price_save'">Girilen fiyatları kaydet</button> <small style="color:var(--rv-muted)">Boş satırlar değişmez. Fiyat değişimi pazaryeri senkronu için ürün güncelleme kancasını tetikler.</small></p>{/if}
	</form>
	{if $pages > 1}<div class="rv-pager">
		{if $page > 1}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=pricing&amp;page={$page-1}&amp;q={$q|escape:'url'}">‹ Önceki</a>{/if}
		<span style="padding:5px 8px">{$page} / {$pages}</span>
		{if $page < $pages}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=pricing&amp;page={$page+1}&amp;q={$q|escape:'url'}">Sonraki ›</a>{/if}
	</div>{/if}
</div>
