<div class="rd-grid c3" style="margin-bottom:20px">
	<div class="rd-stat"><b>{$totals.pending|string_format:"%.2f"} ₺</b><span>Beklemede</span></div>
	<div class="rd-stat"><b>{$totals.approved|string_format:"%.2f"} ₺</b><span>Onaylandı</span></div>
	<div class="rd-stat"><b>{$totals.cancelled|string_format:"%.2f"} ₺</b><span>İptal</span></div>
</div>
<div class="rd-card"><h3>Komisyon kayıtları</h3>
	<div class="rd-toolbar"><a class="rd-btn sm ghost" href="?s=earnings">Tümü</a>{foreach $statuses as $k => $l}<a class="rd-btn sm ghost" href="?s=earnings&st={$k}">{$l}</a>{/foreach}<a class="rd-btn sm" href="?s=earnings&export=csv">CSV indir</a></div>
	<p style="color:var(--rd-muted);font-size:13px">Komisyonlar sipariş teslim edilince onaylanır; iptal/iade olursa iptal edilir. Bu sistemde ödeme yapılmaz; kayıtlar raporlama içindir.</p>
	{if $rows}<div class="rd-resp"><table><thead><tr><th>Tarih</th><th>Sipariş</th><th>Tür</th><th>Sipariş tutarı</th><th>Oran</th><th>Komisyon</th><th>Durum</th></tr></thead><tbody>
	{foreach $rows as $r}<tr><td>{$r.date_add}</td><td>#{$r.id_order}</td><td>{if $r.type == 'override'}Alt bayi payı{if $r.source_name} ({$r.source_name}){/if}{else}Doğrudan{/if}</td><td>{$r.order_total|string_format:"%.2f"} ₺</td><td>%{$r.rate|string_format:"%.2f"}</td><td>{$r.amount|string_format:"%.2f"} ₺</td><td>{$statuses[$r.status]|default:$r.status}</td></tr>{/foreach}
	</tbody></table></div>{else}<p style="color:var(--rd-muted)">Kayıt yok.</p>{/if}
</div>
