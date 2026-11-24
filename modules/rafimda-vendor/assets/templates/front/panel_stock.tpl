<div class="rv-card">
	<div class="rv-grid c4" style="margin-bottom:16px">
		<a class="rv-stat" href="{$rvDomain}satici-panel?s=stock"><b>{$sum.total}</b><span>Toplam ürün</span></a>
		<a class="rv-stat" href="{$rvDomain}satici-panel?s=stock&amp;f=out"><b>{$sum.out}</b><span>Stokta yok</span></a>
		<a class="rv-stat" href="{$rvDomain}satici-panel?s=stock&amp;f=low"><b>{$sum.low}</b><span>Azalan (≤{$lowLimit})</span></a>
		<a class="rv-stat" href="{$rvDomain}satici-panel?s=stock&amp;f=inactive"><b>{$sum.inactive}</b><span>Pasif</span></a>
	</div>
	{if $sum.variant > 0}<div class="rv-alert info">{$sum.variant} varyantlı ürünün stoku varyant bazlıdır; ürün düzenleme ekranından yönetilir.</div>{/if}
	<form method="get" action="{$rvDomain}satici-panel" class="rv-toolbar" style="margin:0 0 10px">
		<input type="hidden" name="s" value="stock"><input type="hidden" name="f" value="{$filter}">
		<input type="text" name="q" value="{$q}" placeholder="Ürün adı, stok kodu, barkod" style="max-width:280px"><button class="rv-btn ghost sm" type="submit">Ara</button>
	</form>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="stock_save">
	<table class="rv-resp"><thead><tr><th>Ürün</th><th>Stok kodu</th><th>Mevcut stok</th>{if $can.edit}<th>Yeni stok</th>{/if}</tr></thead><tbody>
	{foreach $rows as $p}<tr>
		<td data-l="Ürün"><a href="{$rvDomain}satici-panel?s=products&amp;a=edit&amp;id={$p.id_product}"><strong>{$p.product_name}</strong></a>{if !$p.active} <span class="pill off">Pasif</span>{/if}</td>
		<td data-l="Stok kodu">{$p.stock_code}</td>
		<td data-l="Mevcut"><span class="pill {if $p.stock <= 0}off{elseif $p.stock <= $lowLimit}warn{else}ok{/if}">{$p.stock|string_format:"%g"}</span></td>
		{if $can.edit}<td data-l="Yeni stok">{if $p.has_variants}<small style="color:var(--rv-muted)">Varyantlı</small>{else}<input type="number" min="0" step="1" inputmode="numeric" name="rows[{$p.id_product}][stock]" placeholder="{$p.stock|string_format:"%g"}" style="max-width:110px">{/if}</td>{/if}
	</tr>{foreachelse}<tr><td colspan="4" style="text-align:center;color:var(--rv-muted);padding:30px">Kayıt yok.</td></tr>{/foreach}
	</tbody></table>
	{if $can.edit && $rows}<p><button class="rv-btn" type="submit">Girilen stokları kaydet</button> <small style="color:var(--rv-muted)">Boş bırakılan satırlar değişmez. Tek seferde en fazla 100 ürün.</small></p>{/if}
	</form>
	{if $pages > 1}<div class="rv-pager">
		{if $page > 1}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=stock&amp;page={$page-1}&amp;q={$q|escape:'url'}&amp;f={$filter|escape:'url'}">‹ Önceki</a>{/if}
		<span style="padding:5px 8px">{$page} / {$pages}</span>
		{if $page < $pages}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=stock&amp;page={$page+1}&amp;q={$q|escape:'url'}&amp;f={$filter|escape:'url'}">Sonraki ›</a>{/if}
	</div>{/if}
</div>
