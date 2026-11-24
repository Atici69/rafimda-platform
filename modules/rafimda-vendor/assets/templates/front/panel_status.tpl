{include file="file:{$rvTplDir}guest_head.tpl"}
<body class="rv"><div class="rv-guest wide"><div class="rv-card">
	<h1>{$vendor.store_name}</h1>
	{if $flash}<div class="rv-alert {$flash.type}">{$flash.message}</div>{/if}
	<p>Mağaza durumu: <span class="pill warn">{$statusLabel}</span></p>
	{if $app}
	<div class="rv-appbox">
		<p>Başvuru numarası: <strong>{$app.app_no}</strong> · Başvuru durumu: <span class="pill {if $app.status == 'approved'}ok{elseif $app.status == 'rejected'}bad{else}warn{/if}">{$appStatusLabel}</span></p>
		<ol class="rv-steps">{foreach ['new','reviewing','docs_required','approved'] as $st}<li class="{if $app.status == $st}is-cur{/if}">{$appStatuses[$st]}</li>{/foreach}</ol>
	</div>
	{if $app.status == 'docs_required' && $app.missing_docs}<div class="rv-alert warning"><strong>Eksik belge bildirimi:</strong><br>{$app.missing_docs|escape|nl2br nofilter}</div>{/if}
	{if $app.status == 'rejected' && $app.admin_note}<div class="rv-alert danger"><strong>Ret gerekçesi:</strong> {$app.admin_note}</div>{/if}
	{/if}
	{if $vendor.status == 'pending'}<p>Başvurunuz değerlendiriliyor. Onaylandığında bu sayfadan satıcı paneline erişebileceksiniz.</p>{/if}
	{if $vendor.status == 'rejected' && !$app}<p>Başvurunuz reddedildi.{if $vendor.rejection_reason} Neden: {$vendor.rejection_reason}{/if}</p>{/if}
	{if $vendor.status == 'suspended'}<p>Mağazanız askıya alındı. Destek ile iletişime geçin.</p>{/if}
	{if $app}
	<h3>Belgeler</h3>
	{if $appDocs}<table class="rv-table"><thead><tr><th>Tür</th><th>Dosya</th><th>Boyut</th><th>Tarih</th></tr></thead><tbody>
	{foreach $appDocs as $d}<tr><td>{$docTypes[$d.doc_type]|default:$d.doc_type}</td><td><a href="{$panelUrl}&a=doc&id={$d.id_doc}">{$d.original_name}</a></td><td>{($d.size/1024)|string_format:'%.0f'} KB</td><td>{$d.date_add}</td></tr>{/foreach}
	</tbody></table>{else}<p class="rv-muted">Henüz belge yüklenmedi.</p>{/if}
	{if $canUpload}
	<form method="post" enctype="multipart/form-data" class="rv-upload">
		<input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="upload_doc">
		<div class="rv-row2">
			<div><label for="f_doc_type">Belge türü</label><select id="f_doc_type" name="doc_type">{foreach $docTypes as $k => $l}<option value="{$k}">{$l}</option>{/foreach}</select></div>
			<div><label for="f_document">Dosya (PDF, JPG, PNG · en fazla 5 MB)</label><input type="file" id="f_document" name="document" accept=".pdf,.jpg,.jpeg,.png" required></div>
		</div>
		<p><button class="rv-btn" type="submit">Belgeyi yükle</button></p>
	</form>
	{/if}
	<h3>Başvuru geçmişi</h3>
	<ul class="rv-history">{foreach $appHistory as $h}<li><span>{$h.date_add}</span> {$appStatuses[$h.to_status]|default:$h.to_status}{if $h.note} — {$h.note}{/if}</li>{/foreach}</ul>
	{/if}
	<form method="post" action="{$logoutUrl}"><input type="hidden" name="token" value="{$rvToken}"><button class="rv-btn navy" type="submit">Çıkış yap</button></form>
</div></div></body></html>
