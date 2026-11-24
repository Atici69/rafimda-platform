<div class="rd-card"><h3>Alt bayi davet bağlantısı</h3>
	<p>Alt bayi adaylarına bu bağlantıyı gönderin; başvuruları yönetici onayına düşer.</p>
	<input type="text" readonly value="{$inviteUrl}" onclick="this.select()">
	<p style="color:var(--rd-muted);font-size:13px">Paket kontenjanı: {$subCount} / {if $maxSub > 0}{$maxSub}{else}0 (paketiniz alt bayiye izin vermiyor){/if}</p>
</div>
<div class="rd-card" style="margin-top:16px"><h3>Alt bayilerim</h3>
	{if $subs}<div class="rd-resp"><table><thead><tr><th>Bayi</th><th>Şehir</th><th>Durum</th><th>Müşteri</th><th>Satış</th><th>Komisyon</th><th></th></tr></thead><tbody>
	{foreach $subs as $s}<tr><td>{$s.company_name}<br><small>{$s.contact_name}</small></td><td>{$s.city}</td>
		<td>{if $s.status == 'active'}{if $s.paused_by_parent}Sizin tarafınızdan durduruldu{else}Aktif{/if}{elseif $s.status == 'pending'}Yönetici onayı bekliyor{elseif $s.status == 'suspended'}Yönetici tarafından askıda{else}Reddedildi{/if}</td>
		<td>{$s.customers}</td><td>{$s.sales|string_format:"%.2f"} ₺</td><td>{$s.comm|string_format:"%.2f"} ₺</td>
		<td>{if $s.status == 'active'}<form method="post"><input type="hidden" name="token" value="{$rdToken}"><input type="hidden" name="id_dealer" value="{$s.id_dealer}"><input type="hidden" name="action" value="{if $s.paused_by_parent}sub_resume{else}sub_pause{/if}"><button class="rd-btn sm {if !$s.paused_by_parent}ghost{/if}" type="submit" data-confirm="{if $s.paused_by_parent}Bu alt bayi tekrar etkinleştirilsin mi?{else}Bu alt bayi durdurulsun mu?{/if}">{if $s.paused_by_parent}Devam ettir{else}Durdur{/if}</button></form>{/if}</td></tr>{/foreach}
	</tbody></table></div>{else}<p style="color:var(--rd-muted)">Henüz alt bayiniz yok.</p>{/if}
</div>
