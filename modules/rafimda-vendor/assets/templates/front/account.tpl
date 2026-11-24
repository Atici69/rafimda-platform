<main class="rv-acc">
	<h1>Hesap merkezi</h1>
	{if $acFlash}<div class="rvs-flash rvs-flash--{$acFlash.type|escape}" role="status">{$acFlash.message|escape}</div>{/if}

	<section id="bildirimler">
		<h2 style="font-size:1.1rem">Bildirimler</h2>
		{if $acEmailOff}<p class="rvs-small">E-posta gönderimi henüz yapılandırılmadı; bildirimleriniz burada listelenir.</p>{/if}
		{if $acNotifications}
		<table><tbody>{foreach $acNotifications as $n}<tr{if !$n.is_read} style="font-weight:600"{/if}><td>{$n.date_add|escape}</td><td>{$n.title|escape}<br><span class="rvs-small">{$n.body|truncate:200|escape}</span></td></tr>{/foreach}</tbody></table>
		<form method="post"><input type="hidden" name="token" value="{$acToken|escape}"><input type="hidden" name="action" value="read_all"><button class="rvs-link" type="submit">Tümünü okundu işaretle</button></form>
		{else}<p class="rvs-empty">Bildiriminiz yok.</p>{/if}
	</section>

	<section id="degerlendir">
		<h2 style="font-size:1.1rem">Değerlendirilecek siparişler</h2>
		{if $acDelivered}<ul>{foreach $acDelivered as $o}<li><a href="{$acReviewUrl|escape}?order={$o.id_order}">Sipariş {$o.reference|escape}</a> <span class="rvs-small">{$o.date_add|escape}</span></li>{/foreach}</ul>
		{else}<p class="rvs-empty">Teslim edilmiş siparişiniz olduğunda ürünleri ve mağazayı buradan değerlendirebilirsiniz.</p>{/if}
	</section>

	{if $acInvoices !== null}
	<section id="faturalar">
		<h2 style="font-size:1.1rem">Faturalarım</h2>
		{if $acInvoices}<table><thead><tr><th>Sipariş</th><th>Fatura no</th><th>Tarih</th><th>Tutar</th><th>Durum</th><th></th></tr></thead><tbody>
		{foreach $acInvoices as $i}<tr><td>#{$i.id_order}</td><td>{$i.invoice_no|escape}{if $i.environment == 'test'} <span class="rvs-badge" style="background:#fef3c7;color:#92400e">TEST</span>{/if}</td><td>{$i.sent_at|escape}</td><td>{$i.gross_total|string_format:'%.2f'} TL</td><td>{$acInvoiceStatuses[$i.status]|default:$i.status|escape}{if $i.kind == 'return'} (iade){/if}</td>
			<td>{if $i.pdf_file}<a href="{$acPdfBase|escape}?id={$i.id_invoice}" target="_blank" rel="noopener">PDF görüntüle / indir</a>{/if}</td></tr>{/foreach}
		</tbody></table>
		{else}<p class="rvs-empty">Henüz düzenlenmiş faturanız yok. Faturalar, siparişinizdeki her satıcı tarafından ayrı ayrı düzenlenir.</p>{/if}
	</section>
	{/if}

	<section id="destek">
		<h2 style="font-size:1.1rem">Destek taleplerim</h2>
		{if $acTicket}
			<p><strong>{$acTicket.ticket_no|escape}</strong> · {$acTicket.subject|escape} · {$acStatuses[$acTicket.status]|escape}</p>
			<ul class="rv-history" style="list-style:none;padding:0">{foreach $acTicket.messages as $m}<li style="border-bottom:1px solid #eef1f5;padding:6px 0"><span class="rvs-small">{$m.date_add|escape} · {if $m.author == 'admin'}Rafımda Destek{else}Siz{/if}</span><br>{$m.body|escape|nl2br nofilter}</li>{/foreach}</ul>
			{if $acTicket.status != 'closed'}<form method="post"><input type="hidden" name="token" value="{$acToken|escape}"><input type="hidden" name="action" value="ticket_reply"><input type="hidden" name="id_ticket" value="{$acTicket.id_ticket}">
				<label for="ac-rep">Yanıtınız</label><textarea id="ac-rep" name="body" rows="3" maxlength="5000" required></textarea><p><button class="rvs-btn" type="submit">Gönder</button></p></form>{/if}
			<p><a href="{$acSelf|escape}#destek">← Tüm talepler</a></p>
		{else}
			{if $acTickets}<table><thead><tr><th>No</th><th>Konu</th><th>Durum</th></tr></thead><tbody>{foreach $acTickets as $t}<tr><td><a href="{$acSelf|escape}?t={$t.id_ticket}#destek">{$t.ticket_no|escape}</a></td><td>{$t.subject|escape}</td><td>{$acStatuses[$t.status]|escape}</td></tr>{/foreach}</tbody></table>{/if}
			<form method="post"><input type="hidden" name="token" value="{$acToken|escape}"><input type="hidden" name="action" value="ticket_create">
				<label for="ac-cat">Kategori</label><select id="ac-cat" name="category">{foreach $acCategories as $k => $l}{if $k != 'store' && $k != 'application'}<option value="{$k}">{$l|escape}</option>{/if}{/foreach}</select>
				<label for="ac-ord">İlgili sipariş (isteğe bağlı)</label><select id="ac-ord" name="id_order"><option value="0">—</option>{foreach $acOrders as $o}<option value="{$o.id_order}">{$o.reference|escape}</option>{/foreach}</select>
				<label for="ac-sub">Konu</label><input id="ac-sub" name="subject" maxlength="190" required>
				<label for="ac-body">Açıklama</label><textarea id="ac-body" name="body" rows="4" maxlength="5000" required></textarea>
				<p><button class="rvs-btn" type="submit">Talep oluştur</button></p>
			</form>
		{/if}
	</section>

	<section id="takip">
		<h2 style="font-size:1.1rem">Takip ettiğim mağazalar</h2>
		{if $acFollowed}<ul>{foreach $acFollowed as $v}<li><a href="{$acStoreBase|escape}?s={$v.store_slug|escape:'url'}">{$v.store_name|escape}</a>
			<form method="post" class="rvs-inline"><input type="hidden" name="token" value="{$acToken|escape}"><input type="hidden" name="action" value="unfollow"><input type="hidden" name="id_vendor" value="{$v.id_vendor}"><button class="rvs-link" type="submit">Takibi bırak</button></form></li>{/foreach}</ul>
		{else}<p class="rvs-empty">Henüz takip ettiğiniz mağaza yok.</p>{/if}
	</section>

	{if $acConsents !== null}
	<section id="izinler">
		<h2 style="font-size:1.1rem">İletişim izinlerim</h2>
		<p class="rvs-small">Sipariş ve fatura bildirimleri bu izinlerden bağımsızdır. İzinleri istediğiniz zaman geri alabilirsiniz.</p>
		<form method="post"><input type="hidden" name="token" value="{$acToken|escape}"><input type="hidden" name="action" value="consents">
			{foreach ['commercial_email', 'commercial_sms', 'commercial_phone', 'explicit_consent'] as $k}
			<div style="margin:6px 0"><input type="hidden" name="{$k}" value="0"><label style="display:flex;gap:8px;font-weight:400"><input type="checkbox" name="{$k}" value="1" style="width:auto"{if $acConsents[$k]} checked{/if}> {$acConsentLabels[$k]|escape}</label></div>
			{/foreach}
			<p><button class="rvs-btn" type="submit">Kaydet</button> <a href="{$acCookieUrl|escape}">Çerez tercihleri</a></p>
		</form>
	</section>
	{/if}
</main>
