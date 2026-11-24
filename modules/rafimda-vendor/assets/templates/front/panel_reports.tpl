<div class="rv-card">
	<div class="rv-toolbar" style="flex-wrap:wrap;align-items:center"><h2 style="margin:0;flex:1">Satış raporu</h2>
		{foreach [7, 30, 90] as $d}<a class="rv-btn sm {if $days == $d}navy{else}ghost{/if}" href="?s=reports&amp;days={$d}">Son {$d} gün</a>{/foreach}</div>
	<p style="color:var(--rv-muted);font-size:13px">İptal ve iade edilen siparişler satış tutarına dahil edilmez.</p>
</div>
<div class="rv-grid c4" style="margin-bottom:20px">
	<div class="rv-stat"><b>{$rep.orders}</b><span>Sipariş</span></div>
	<div class="rv-stat"><b>{$rep.sales|string_format:"%.2f"} ₺</b><span>Satış tutarı</span></div>
	<div class="rv-stat"><b>{$rep.commission|string_format:"%.2f"} ₺</b><span>Komisyon</span></div>
	<div class="rv-stat"><b>{$rep.earning|string_format:"%.2f"} ₺</b><span>Net kazanç (ort. sepet {$rep.avg|string_format:"%.2f"} ₺)</span></div>
</div>
<div class="rv-grid c2">
	<div class="rv-card"><h3>Günlük satış</h3>
		<div class="rv-bars" role="img" aria-label="Günlük satış grafiği" style="display:flex;align-items:flex-end;gap:2px;height:140px;border-bottom:1px solid var(--rv-line)">
		{foreach $rep.daily as $d}<span title="{$d.day}: {$d.sales|string_format:"%.2f"} ₺ ({$d.orders} sipariş)" style="flex:1;min-width:2px;background:var(--rv-orange);border-radius:2px 2px 0 0;height:{if $maxDaily > 0}{($d.sales / $maxDaily * 100)|string_format:"%.0f"}{else}0{/if}%"></span>{/foreach}
		</div>
		<p style="color:var(--rv-muted);font-size:12px;margin-top:6px">{$rep.daily[0].day} – {$rep.daily[$rep.daily|@count - 1].day}</p>
	</div>
	<div class="rv-card"><h3>Sipariş durumları</h3>
		<table><tbody>{foreach $rep.status as $k => $n}<tr><td>{$statuses[$k]|default:$k}</td><td style="text-align:right"><strong>{$n}</strong></td></tr>{foreachelse}<tr><td style="color:var(--rv-muted)">Bu dönemde sipariş yok.</td></tr>{/foreach}</tbody></table>
	</div>
</div>
<div class="rv-card"><h3>En çok satan ürünler</h3>
	<table class="rv-resp"><thead><tr><th>Ürün</th><th>Adet</th><th>Tutar</th></tr></thead><tbody>
	{foreach $rep.top as $t}<tr><td data-l="Ürün">{$t.product_name}</td><td data-l="Adet">{$t.qty|string_format:"%g"}</td><td data-l="Tutar">{$t.total|string_format:"%.2f"} ₺</td></tr>{foreachelse}<tr><td colspan="3" style="text-align:center;color:var(--rv-muted);padding:20px">Bu dönemde satış yok.</td></tr>{/foreach}
	</tbody></table>
</div>
