<div class="rd-card"><h3>Mevcut paket</h3>
	{if $plan}<p><strong>{$plan.name}</strong> — {$plan.monthly_price|string_format:"%.2f"} ₺/ay · komisyon %{$plan.commission_rate|string_format:"%.2f"} · alt bayi kontenjanı {$plan.max_sub_dealers}</p>
	<p>{if $dealer.plan_expires_at}Bitiş: {$dealer.plan_expires_at}{else}Süresiz{/if}</p>{else}<p>Atanmış geçerli paketiniz yok (varsayılan oran uygulanır).</p>{/if}
	<p style="font-size:13px;color:var(--rd-muted)">Abonelik ücreti bu sistemde tahsil edilmez; kayıt ve takip içindir.</p>
</div>
<div class="rd-card" style="margin-top:16px"><h3>Paketler</h3>
	<div class="rd-resp"><table><thead><tr><th>Paket</th><th>Aylık</th><th>Komisyon</th><th>Alt bayi</th><th></th></tr></thead><tbody>
	{foreach $plans as $p}<tr><td>{$p.name}</td><td>{$p.monthly_price|string_format:"%.2f"} ₺</td><td>%{$p.commission_rate|string_format:"%.2f"}</td><td>{$p.max_sub_dealers}</td>
	<td><form method="post"><input type="hidden" name="token" value="{$rdToken}"><input type="hidden" name="action" value="plan_request"><input type="hidden" name="plan_code" value="{$p.code}"><button class="rd-btn sm" type="submit" data-confirm="Bu paket için talep gönderilsin mi?">Talep et</button></form></td></tr>{/foreach}
	</tbody></table></div>
</div>
<div class="rd-card" style="margin-top:16px"><h3>Abonelik geçmişi</h3>
	{if $history}<div class="rd-resp"><table><thead><tr><th>Paket</th><th>Durum</th><th>Başlangıç</th><th>Bitiş</th><th>Talep tarihi</th></tr></thead><tbody>
	{foreach $history as $h}<tr><td>{$h.plan_name|default:$h.plan_code}</td><td>{$subStatuses[$h.status]|default:$h.status}</td><td>{$h.starts_at}</td><td>{$h.expires_at}</td><td>{$h.date_add}</td></tr>{/foreach}
	</tbody></table></div>{else}<p style="color:var(--rd-muted)">Kayıt yok.</p>{/if}
</div>
