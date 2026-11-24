<div class="rv-card"><h3>Siparişler</h3>
	<p style="color:var(--rv-muted);font-size:13px">Yalnızca sizin ürünlerinizi içeren siparişler gösterilir (salt okunur). Durum yönetimi ve kargo sonraki aşamada.</p>
	{if $orders}
	<table class="rv-resp"><thead><tr><th>Sipariş</th><th>Müşteri</th><th>Şehir</th><th>Kalem</th><th>Tutar</th><th>Tarih</th></tr></thead><tbody>
	{foreach $orders as $o}<tr><td data-l="Sipariş">#{$o.reference|default:$o.id_order}</td><td data-l="Müşteri">{$o.customer_name}</td><td data-l="Şehir">{$o.address_city}</td>
		<td data-l="Kalem">{$o.line_count}</td><td data-l="Tutar">{$o.vendor_total|string_format:"%.2f"} ₺</td><td data-l="Tarih">{$o.date_add}</td></tr>{/foreach}
	</tbody></table>{else}<p>Henüz sipariş yok.</p>{/if}
</div>
