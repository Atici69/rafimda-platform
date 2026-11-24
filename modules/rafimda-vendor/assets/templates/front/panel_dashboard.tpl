{if $links|default:[]}<div class="rv-card" style="padding:14px 20px"><strong>Hızlı işlemler:</strong>
	{foreach $links as $l} <a class="rv-btn sm ghost" href="{$l.url}" style="margin:3px 4px 3px 0">{$l.label}</a>{/foreach}</div>{/if}
<div class="rv-grid c4" style="margin-bottom:20px">
	<div class="rv-stat"><b>{$stats.total}</b><span>Toplam ürün</span></div>
	<div class="rv-stat"><b>{$stats.active}</b><span>Aktif ürün</span></div>
	<div class="rv-stat"><b>{$stats.passive}</b><span>Pasif ürün</span></div>
	<div class="rv-stat"><b>{$stats.low_stock}</b><span>Düşük stok (≤{$lowLimit|default:5})</span></div>
</div>
<div class="rv-grid c3" style="margin-bottom:20px">
	<div class="rv-stat"><b>{$balances.available|string_format:"%.2f"} ₺</b><span>Ödenebilir bakiye</span></div>
	<div class="rv-stat"><b>{$balances.pending|string_format:"%.2f"} ₺</b><span>Bekleyen bakiye</span></div>
	<div class="rv-stat"><b>{$balances.paid_out|string_format:"%.2f"} ₺</b><span>Ödenen toplam</span></div>
</div>
<div class="rv-grid c2">
	<div class="rv-card"><h3>Son siparişler</h3>
		{if $recentOrders}<table><thead><tr><th>Sipariş</th><th>Tutar</th><th>Tarih</th></tr></thead><tbody>
		{foreach $recentOrders as $o}<tr><td>#{$o.reference|default:$o.id_order}</td><td>{$o.subtotal|string_format:"%.2f"} ₺</td><td>{$o.date_add}</td></tr>{/foreach}
		</tbody></table>{else}<p style="color:var(--rv-muted)">Henüz siparişiniz yok.</p>{/if}
	</div>
	<div class="rv-card"><h3>Son işlemler</h3>
		{if $audit}<table><tbody>{foreach $audit as $a}<tr><td>{$a.action}</td><td style="color:var(--rv-muted)">{$a.date_add}</td></tr>{/foreach}</tbody></table>
		{else}<p style="color:var(--rv-muted)">Kayıt yok.</p>{/if}
	</div>
</div>
