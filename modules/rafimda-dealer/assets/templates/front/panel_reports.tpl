<div class="rd-grid c4" style="margin-bottom:20px">
	<div class="rd-stat"><b>{$summary.customers}</b><span>Müşteri</span></div>
	<div class="rd-stat"><b>{$summary.sales|string_format:"%.2f"} ₺</b><span>Toplam satış</span></div>
	<div class="rd-stat"><b>{$summary.override|string_format:"%.2f"} ₺</b><span>Alt bayi payı</span></div>
	<div class="rd-stat"><b>{$summary.sub_dealers}</b><span>Alt bayi</span></div>
</div>
<div class="rd-card"><h3>Son 12 ay satış ve komisyon</h3>
	<div class="rd-resp"><table><thead><tr><th>Ay</th><th style="width:40%">Satış</th><th>Sipariş</th><th>Satış</th><th>Komisyon</th></tr></thead><tbody>
	{foreach $months as $m}<tr><td>{$m.ym}</td><td><div style="background:var(--rd-line);border-radius:4px;height:12px"><div style="background:var(--rd-orange);height:12px;border-radius:4px;width:{if $maxSales > 0}{($m.sales / $maxSales * 100)|string_format:"%.0f"}{else}0{/if}%"></div></div></td><td>{$m.orders}</td><td>{$m.sales|string_format:"%.2f"} ₺</td><td>{$m.commission|string_format:"%.2f"} ₺</td></tr>{/foreach}
	</tbody></table></div>
</div>
<div class="rd-grid c2" style="margin-top:16px">
	<div class="rd-card"><h3>En çok alışveriş yapan müşteriler</h3>
		{if $topCustomers}<table><tbody>{foreach $topCustomers as $c}<tr><td>{$c.user_full_name}</td><td>{$c.orders} sipariş</td><td>{$c.total|string_format:"%.2f"} ₺</td></tr>{/foreach}</tbody></table>{else}<p style="color:var(--rd-muted)">Veri yok.</p>{/if}
	</div>
	{if $dealer.id_parent == 0}<div class="rd-card"><h3>Alt bayi sıralaması</h3>
		{if $subs}<table><tbody>{foreach $subs as $s}<tr><td>{$s.company_name}</td><td>{$s.customers} müşteri</td><td>{$s.sales|string_format:"%.2f"} ₺</td></tr>{/foreach}</tbody></table>{else}<p style="color:var(--rd-muted)">Alt bayi yok.</p>{/if}
	</div>{/if}
</div>
