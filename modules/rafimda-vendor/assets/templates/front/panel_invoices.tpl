<div class="rv-card">
	<h2>Faturalarım</h2>
	<div class="rv-alert {if $riLive}warning{else}info{/if}">{if $riLive}Canlı fatura gönderimi açık: gönderdiğiniz belgeler entegratörünüz üzerinden gerçek mali belge olur.{else}Canlı gönderim kapalı: belgeler <strong>TEST</strong> niteliğindedir, mali değeri yoktur.{/if}
		Satış faturalarınızı <strong>siz</strong> (mağazanız adına) düzenlersiniz; Rafımda size yalnızca hizmet/komisyon faturası keser.</div>
	<div class="rv-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
		<div>
			<h3>Firma ve vergi bilgileri</h3>
			<p>{$riTax.legal_name|default:'Unvan girilmedi'}<br>VD: {$riTax.tax_office|default:'—'} · VKN/TCKN: {$riTax.tax_number|default:'—'}</p>
			<p style="color:var(--rv-muted);font-size:13px">Değiştirmek için <a href="?s=store">Mağaza Yönetimi</a>.</p>
		</div>
		<div>
			<h3>Entegratör bağlantısı</h3>
			<p>{if $riAccount}<strong>{$riAccount.integrator}</strong> · {$riAccount.environment} · {if $riAccount.status == 'ok'}<span class="pill ok">bağlantı başarılı</span>{elseif $riAccount.status == 'failed'}<span class="pill bad">başarısız</span>{else}<span class="pill warn">test edilmedi</span>{/if}{if $riAccount.last_error}<br><small>{$riAccount.last_error}</small>{/if}{else}Tanımlı değil{/if}</p>
			{if $riCanManage}
			<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="account">
				<label for="ri_int">Entegratör</label><select id="ri_int" name="integrator">{foreach $riAdapters as $k => $a}<option value="{$k}"{if $riAccount && $riAccount.integrator == $k} selected{/if}>{$a.label}</option>{/foreach}</select>
				<label for="ri_env">Ortam</label><select id="ri_env" name="environment"><option value="test">Test</option><option value="live">Canlı</option></select>
				<label for="ri_ef">e-Fatura mükellefiyetiniz</label><select id="ri_ef" name="efatura_user">{foreach ['unknown' => 'Bilinmiyor', 'yes' => 'e-Fatura mükellefiyim', 'no' => 'Değilim (e-Arşiv)'] as $k => $l}<option value="{$k}"{if $riAccount && $riAccount.efatura_user == $k} selected{/if}>{$l}</option>{/foreach}</select>
				<label for="ri_user">Kullanıcı adı</label><input id="ri_user" name="username" value="{$riAccount.username|default:''}" autocomplete="off">
				<label for="ri_sec">Şifre / API anahtarı</label><input id="ri_sec" type="password" name="secret" autocomplete="new-password" placeholder="{if $riAccount && $riAccount.has_secret}değiştirmek için yazın{/if}">
				<p><button class="rv-btn" type="submit">Kaydet</button></p>
			</form>
			<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="test"><button class="rv-btn ghost" type="submit">Bağlantıyı test et</button></form>
			{/if}
		</div>
	</div>
	{if $riCanManage && $riPending}
	<h3>Faturası hazırlanmamış siparişler</h3>
	<div class="rv-resp"><table class="rv-table"><thead><tr><th>Sipariş</th><th>Durum</th><th>Tutar</th><th></th></tr></thead><tbody>
	{foreach $riPending as $o}<tr><td>{$o.reference}</td><td>{$o.status}</td><td>{$o.subtotal|string_format:'%.2f'} ₺</td><td><form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="prepare"><input type="hidden" name="id_order" value="{$o.id_order}"><button class="rv-btn ghost" type="submit">Fatura hazırla</button></form></td></tr>{/foreach}
	</tbody></table></div>
	{/if}
	<h3>Fatura geçmişi</h3>
	{if $riInvoices}
	<div class="rv-resp"><table class="rv-table"><thead><tr><th>#</th><th>Tür</th><th>Alıcı</th><th>Tutar</th><th>Durum</th><th>No</th><th></th></tr></thead><tbody>
	{foreach $riInvoices as $i}<tr><td>{$i.id_invoice}</td><td>{$riKinds[$i.kind]}{if $i.kind == 'service'} <small>(Rafımda → size)</small>{/if}</td><td>{$i.buyer_name}</td><td>{$i.gross_total|string_format:'%.2f'} ₺</td>
		<td><span class="pill {if $i.status == 'accepted' || $i.status == 'sent'}ok{elseif $i.status == 'ready'}warn{elseif $i.status == 'cancelled'}off{else}bad{/if}">{$riStatuses[$i.status]}</span>{if $i.environment == 'test' && $i.ettn} <span class="pill warn">TEST</span>{/if}{if $i.last_error}<br><small>{$i.last_error|truncate:120}</small>{/if}</td>
		<td>{$i.invoice_no}</td>
		<td>{if $i.pdf_file}<a href="?s=invoices&a=pdf&id={$i.id_invoice}" target="_blank" rel="noopener">PDF</a>{/if}
		{if $riCanManage && $i.issuer_type == 'vendor'}
			{if in_array($i.status, ['ready','error','rejected']) && !$i.ettn}<form method="post" style="display:inline"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="send"><input type="hidden" name="id_invoice" value="{$i.id_invoice}"><button class="rv-btn" type="submit">Gönder</button></form>{/if}
			{if $i.status != 'cancelled'}<details><summary>İptal</summary><form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id_invoice" value="{$i.id_invoice}"><input name="reason" placeholder="Gerekçe" required><button class="rv-btn ghost" type="submit">İptal et</button></form></details>{/if}
		{/if}</td></tr>{/foreach}
	</tbody></table></div>
	{else}<p style="color:var(--rv-muted)">Henüz fatura yok.</p>{/if}
</div>
