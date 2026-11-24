<div class="rv-card"><h3>Siparişler</h3>
	<p style="color:var(--rv-muted);font-size:13px">Yalnızca sizin ürünlerinizi içeren sipariş kalemleri gösterilir. Teslim/iptal/iade durumları sistem tarafından belirlenir.</p>
	<form method="get" action="{$rvDomain}satici-panel" class="rv-toolbar"><input type="hidden" name="s" value="orders">
		<select name="state" style="width:auto"><option value="">Tüm durumlar</option>{foreach $statuses as $k => $l}<option value="{$k}"{if $state == $k} selected{/if}>{$l}</option>{/foreach}</select>
		<button class="rv-btn ghost sm" type="submit">Filtrele</button></form>
	{if $orders}
	<table class="rv-resp"><thead><tr><th>Sipariş</th><th>Müşteri</th><th>Şehir</th><th>Kalem</th><th>Satış</th><th>Komisyon</th><th>Kazanç</th><th>Durum</th><th></th></tr></thead><tbody>
	{foreach $orders as $o}<tr><td data-l="Sipariş">#{$o.reference|default:$o.id_order}</td><td data-l="Müşteri">{$o.customer_name}</td><td data-l="Şehir">{$o.address_city}</td>
		<td data-l="Kalem">{$o.line_count}</td><td data-l="Satış">{$o.subtotal|string_format:"%.2f"} ₺</td><td data-l="Komisyon">{$o.commission_amount|string_format:"%.2f"} ₺</td><td data-l="Kazanç">{$o.vendor_earning|string_format:"%.2f"} ₺</td>
		<td data-l="Durum"><span class="pill {if $o.status == 'delivered'}ok{elseif $o.status == 'cancelled' || $o.status == 'returned'}off{else}warn{/if}">{$statuses[$o.status]|default:$o.status}</span></td>
		<td><a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=orders&amp;a=view&amp;id={$o.id_vendor_order}">Aç</a></td></tr>{/foreach}
	</tbody></table>{else}<p>Henüz sipariş yok.</p>{/if}
</div>
