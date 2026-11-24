<div class="rd-grid c4" style="margin-bottom:20px">
	<div class="rd-stat"><b>{$summary.customers}</b><span>Müşteri</span></div>
	<div class="rd-stat"><b>{$summary.sales|string_format:"%.2f"} ₺</b><span>Toplam satış (iptal hariç)</span></div>
	<div class="rd-stat"><b>{$totals.pending|string_format:"%.2f"} ₺</b><span>Bekleyen komisyon</span></div>
	<div class="rd-stat"><b>{$totals.approved|string_format:"%.2f"} ₺</b><span>Onaylı komisyon</span></div>
</div>
<div class="rd-grid c2">
	<div class="rd-card"><h3>Hesabım</h3>
		<p>Geçerli komisyon oranı: <strong>%{$rate|string_format:"%.2f"}</strong></p>
		<p>Paket: <strong>{if $plan}{$plan.name}{else}atanmamış{/if}</strong>{if $dealer.plan_expires_at} · bitiş {$dealer.plan_expires_at}{/if}</p>
		{if $dealer.id_parent == 0}<p>Alt bayi: <strong>{$summary.sub_dealers}</strong></p>{/if}
		<p>Referans kodunuz: <strong>{$dealer.referral_code}</strong></p>
		<p style="font-size:13px;color:var(--rd-muted)">Komisyonlar yalnızca hesaplanır ve raporlanır; ödeme bu sistemde yapılmaz.</p>
	</div>
	<div class="rd-card"><h3>Son komisyonlar</h3>
		{if $recent}<div class="rd-resp"><table><thead><tr><th>Sipariş</th><th>Tutar</th><th>Durum</th></tr></thead><tbody>
		{foreach $recent as $r}<tr><td>#{$r.id_order}</td><td>{$r.amount|string_format:"%.2f"} ₺</td><td>{$r.status}</td></tr>{/foreach}
		</tbody></table></div>{else}<p style="color:var(--rd-muted)">Henüz komisyon kaydı yok. Müşterilerinizi referans bağlantınızla yönlendirin.</p>{/if}
	</div>
</div>
