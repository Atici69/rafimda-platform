<div class="rv-card">
	<h2>Bildirimler</h2>
	{if $channels.email != 'configured'}<div class="rv-alert warning">E-posta gönderimi yapılandırılmadı; bildirimler yalnızca bu sayfada görünür. SMS altyapısı yoktur.</div>{/if}
	{if $items}
	<form method="post" style="margin-bottom:10px"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="id" value="0"><button class="rv-btn ghost" type="submit">Tümünü okundu işaretle</button></form>
	<div class="rv-resp"><table class="rv-table"><thead><tr><th>Tarih</th><th>Tür</th><th>Bildirim</th><th></th></tr></thead><tbody>
	{foreach $items as $n}<tr{if !$n.is_read} style="font-weight:600"{/if}><td>{$n.date_add}</td><td>{$types[$n.type]|default:$n.type}</td><td>{$n.title}<br><span style="color:var(--rv-muted);font-weight:400">{$n.body|truncate:160}</span>{if $n.link} <a href="{$n.link}">Aç</a>{/if}</td>
		<td>{if !$n.is_read}<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="id" value="{$n.id_notification}"><button class="rv-btn ghost" type="submit">Okundu</button></form>{/if}</td></tr>{/foreach}
	</tbody></table></div>
	{else}<p style="color:var(--rv-muted)">Bildirim yok.</p>{/if}
</div>
