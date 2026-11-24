<div class="rv-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
<div class="rv-card">
	<h2>Destek taleplerim</h2>
	{if $tickets}
	<div class="rv-resp"><table class="rv-table"><thead><tr><th>No</th><th>Konu</th><th>Durum</th><th>Güncelleme</th></tr></thead><tbody>
	{foreach $tickets as $t}<tr><td><a href="?s=support&t={$t.id_ticket}">{$t.ticket_no}</a></td><td>{$t.subject}</td><td><span class="pill {if $t.status == 'answered'}ok{elseif $t.status == 'closed'}off{else}warn{/if}">{$statuses[$t.status]}</span></td><td>{$t.date_upd}</td></tr>{/foreach}
	</tbody></table></div>
	{else}<p style="color:var(--rv-muted)">Henüz destek talebiniz yok.</p>{/if}
	{if $canManage}
	<h3>Yeni talep</h3>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="create">
		<label for="t_cat">Kategori</label><select id="t_cat" name="category">{foreach $categories as $k => $l}<option value="{$k}">{$l}</option>{/foreach}</select>
		<label for="t_sub">Konu</label><input id="t_sub" name="subject" maxlength="190" required>
		<label for="t_body">Açıklama</label><textarea id="t_body" name="body" rows="4" maxlength="5000" required></textarea>
		<p><button class="rv-btn" type="submit">Talep oluştur</button></p>
	</form>
	{/if}
</div>
{if $ticket}
<div class="rv-card">
	<h2>{$ticket.ticket_no} · {$ticket.subject}</h2>
	<p><span class="pill {if $ticket.status == 'answered'}ok{elseif $ticket.status == 'closed'}off{else}warn{/if}">{$statuses[$ticket.status]}</span> · {$categories[$ticket.category]|default:$ticket.category}</p>
	<ul class="rv-history">{foreach $ticket.messages as $m}<li><span>{$m.date_add} · {if $m.author == 'admin'}Rafımda Destek{else}Siz{/if}</span><br>{$m.body|escape|nl2br nofilter}</li>{/foreach}</ul>
	{if $canManage && $ticket.status != 'closed'}
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="reply"><input type="hidden" name="id_ticket" value="{$ticket.id_ticket}">
		<label for="t_rep">Yanıt</label><textarea id="t_rep" name="body" rows="3" maxlength="5000" required></textarea>
		<p><button class="rv-btn" type="submit">Gönder</button></p>
	</form>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="close"><input type="hidden" name="id_ticket" value="{$ticket.id_ticket}"><button class="rv-btn ghost" type="submit">Talebi kapat</button></form>
	{/if}
</div>
{/if}
</div>
