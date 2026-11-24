<div class="rv-grid c4" style="margin-bottom:20px">
	<div class="rv-stat"><b>{$stats.total}</b><span>Toplam ürün</span></div>
	<div class="rv-stat"><b>{$stats.active}</b><span>Aktif ürün</span></div>
	<div class="rv-stat"><b>{$stats.passive}</b><span>Pasif ürün</span></div>
	<div class="rv-stat"><b>{$stats.low_stock}</b><span>Düşük stok (≤5)</span></div>
</div>
<div class="rv-grid c2">
	<div class="rv-card"><h3>Son siparişler</h3>
		{if $recentOrders}<table><thead><tr><th>Sipariş</th><th>Tutar</th><th>Tarih</th></tr></thead><tbody>
		{foreach $recentOrders as $o}<tr><td>#{$o.reference|default:$o.id_order}</td><td>{$o.vendor_total|string_format:"%.2f"} ₺</td><td>{$o.date_add}</td></tr>{/foreach}
		</tbody></table>{else}<p style="color:var(--rv-muted)">Henüz siparişiniz yok.</p>{/if}
	</div>
	<div class="rv-card"><h3>Son işlemler</h3>
		{if $audit}<table><tbody>{foreach $audit as $a}<tr><td>{$a.action}</td><td style="color:var(--rv-muted)">{$a.date_add}</td></tr>{/foreach}</tbody></table>
		{else}<p style="color:var(--rv-muted)">Kayıt yok.</p>{/if}
	</div>
</div>
