<div class="rv-card">
	<div class="rv-grid c4" style="margin-bottom:16px">
		<div class="rv-stat"><b>{$sum.customers}</b><span>Müşteri</span></div>
		<div class="rv-stat"><b>{$sum.repeat}</b><span>Tekrar alışveriş</span></div>
		<div class="rv-stat"><b>{$sum.orders}</b><span>Sipariş</span></div>
		<div class="rv-stat"><b>{$sum.revenue|string_format:"%.2f"} ₺</b><span>Ciro</span></div>
	</div>
	<div class="rv-alert info">Gizlilik: yalnızca sizden alışveriş eden müşteriler listelenir; e-posta ve telefon burada gösterilmez (kargo bilgisi sipariş detayındadır).</div>
	<form method="get" action="{$rvDomain}satici-panel" class="rv-toolbar" style="margin:0 0 10px"><input type="hidden" name="s" value="customers">
		<input type="text" name="q" value="{$q}" placeholder="Müşteri adı" style="max-width:240px"><button class="rv-btn ghost sm" type="submit">Ara</button></form>
	<table class="rv-resp"><thead><tr><th>Müşteri</th><th>Şehir</th><th>Sipariş</th><th>Toplam</th><th>Son sipariş</th></tr></thead><tbody>
	{foreach $rows as $c}<tr><td data-l="Müşteri"><strong>{$c.customer_name}</strong></td><td data-l="Şehir">{$c.city}</td><td data-l="Sipariş">{$c.orders}</td><td data-l="Toplam">{$c.spent|string_format:"%.2f"} ₺</td><td data-l="Son">{$c.last_order}</td></tr>{foreachelse}<tr><td colspan="5" style="text-align:center;color:var(--rv-muted);padding:30px">Henüz müşteri yok.</td></tr>{/foreach}
	</tbody></table>
	{if $pages > 1}<div class="rv-pager">
		{if $page > 1}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=customers&amp;page={$page-1}&amp;q={$q|escape:'url'}">‹ Önceki</a>{/if}
		<span style="padding:5px 8px">{$page} / {$pages}</span>
		{if $page < $pages}<a class="rv-btn ghost sm" href="{$rvDomain}satici-panel?s=customers&amp;page={$page+1}&amp;q={$q|escape:'url'}">Sonraki ›</a>{/if}
	</div>{/if}
</div>
