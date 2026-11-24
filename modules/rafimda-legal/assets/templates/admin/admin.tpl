{if $rlFlash}<div class="alert alert-{$rlFlash.type|escape}">{$rlFlash.message|escape}</div>{/if}
<div class="alert alert-warning">
	<strong>Hukuki uyarı:</strong> Bu metinler <strong>taslaktır</strong> ve hukuki görüş değildir. Yayından önce avukat / KVKK danışmanı incelemesi gerekir.
	İnceleme bekleyen belge: <strong>{$rlReviewCount}</strong>.
	{if $rlMissing}<br>Doldurulmamış şirket bilgisi ({$rlMissing|count}): {foreach $rlMissing as $m}<span class="badge text-bg-light me-1">{$m|escape}</span>{/foreach} — <a href="?tab=company">Şirket bilgilerini doldur</a>{/if}
</div>
<ul class="nav nav-tabs mb-3">
	{foreach ['documents' => 'Belgeler', 'company' => 'Şirket bilgileri', 'acceptances' => 'Kabul kayıtları', 'consents' => 'İzin kayıtları'] as $k => $l}
	<li class="nav-item"><a class="nav-link{if $rlTab == $k || ($rlTab == 'edit' && $k == 'documents')} active{/if}" href="?tab={$k}">{$l}</a></li>
	{/foreach}
</ul>

{if $rlTab == 'documents'}
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th>Belge</th><th>Grup</th><th>Durum</th><th>Sürüm</th><th>Hukuki inceleme</th><th></th></tr></thead><tbody>
{foreach $rlDocs as $d}<tr>
	<td><strong>{$d.title|escape}</strong><br><small class="text-muted">/hukuki?belge={$d.slug|escape}</small></td>
	<td>{$rlGroups[$d.grp]|default:$d.grp|escape}</td>
	<td><span class="badge text-bg-{if $d.status == 'published'}success{elseif $d.status == 'passive'}secondary{else}warning{/if}">{$rlStatuses[$d.status]|escape}</span></td>
	<td>{$d.version_label|default:'—'|escape}{if $d.published_at}<br><small class="text-muted">{$d.published_at|escape}</small>{/if}</td>
	<td>{if $d.needs_review}<span class="badge text-bg-danger">İnceleme gerekli</span><br><small>{$d.review_note|escape}</small>{else}<span class="badge text-bg-success">İncelendi</span>{/if}</td>
	<td><a class="btn btn-sm btn-outline-primary" href="?tab=edit&id={$d.id_document}">Düzenle</a></td>
</tr>{/foreach}
</tbody></table></div></div>
<form method="post" class="mt-3"><input type="hidden" name="token" value="{$rlToken|escape}"><input type="hidden" name="rlAction" value="cms_sync">
	<button class="btn btn-outline-dark btn-sm text-start text-wrap mw-100" style="white-space:normal" type="submit">Ödeme sayfasındaki CMS metinlerini (mesafeli-satis, gizlilik) yayındaki sürümle güncelle</button>
	<span class="small text-muted ms-2">Yalnızca bu düğmeyle, açık işlemle çalışır.</span>
</form>
{/if}

{if $rlTab == 'edit' && $rlDoc}
<p><a href="?tab=documents">← Belgeler</a></p>
<div class="row g-3">
	<div class="col-lg-7">
		<div class="card"><div class="card-body">
			<h2 class="h5">{$rlDoc.title|escape} <small class="text-muted">{if $rlVer}sürüm {$rlVer.version|escape}{if !$rlVer.published_at} (taslak){/if}{/if}</small></h2>
			<form method="post">
				<input type="hidden" name="token" value="{$rlToken|escape}"><input type="hidden" name="rlAction" value="save_version"><input type="hidden" name="id_document" value="{$rlDoc.id_document}">
				<label class="form-label small" for="rl-body">Metin — biçim: <code>## Başlık</code>, <code>### Alt başlık</code>, <code>- madde</code>, <code>**kalın**</code>; şirket bilgisi: <code>{literal}{{SIRKET_UNVANI}}{/literal}</code> vb. HTML kabul edilmez.</label>
				<textarea class="form-control font-monospace" id="rl-body" name="body" rows="18">{$rlVer.body|default:''|escape}</textarea>
				<input class="form-control mt-2" name="change_note" placeholder="Değişiklik notu (ör. avukat incelemesi sonrası düzeltmeler)">
				<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="publish" value="1" id="rl-pub"><label class="form-check-label" for="rl-pub">Kaydedince yayınla (yeni kabuller bu sürüme bağlanır)</label></div>
				<button class="btn btn-primary mt-2" type="submit">Yeni sürüm olarak kaydet</button>
				<p class="small text-muted mt-1">Yayınlanmış sürümler değiştirilmez; her kayıt yeni sürüm oluşturur.</p>
			</form>
		</div></div>
		<div class="card mt-3"><div class="card-body"><h3 class="h6">Önizleme</h3><div class="border rounded p-3 bg-white">{$rlPreview nofilter}</div></div></div>
	</div>
	<div class="col-lg-5">
		<div class="card"><div class="card-body">
			<h3 class="h6">Durum</h3>
			<form method="post">
				<input type="hidden" name="token" value="{$rlToken|escape}"><input type="hidden" name="rlAction" value="meta"><input type="hidden" name="id_document" value="{$rlDoc.id_document}">
				<select class="form-select mb-2" name="status">{foreach $rlStatuses as $k => $l}<option value="{$k}"{if $rlDoc.status == $k} selected{/if}>{$l|escape}</option>{/foreach}</select>
				<div class="form-check"><input class="form-check-input" type="checkbox" name="needs_review" value="1" id="rl-rev"{if $rlDoc.needs_review} checked{/if}><label class="form-check-label" for="rl-rev">Hukuki inceleme gerekli</label></div>
				<input class="form-control my-2" name="review_note" value="{$rlDoc.review_note|escape}" placeholder="İnceleme notu">
				<button class="btn btn-outline-primary btn-sm" type="submit">Kaydet</button>
				{if $rlDoc.grp == 'dealer'}<p class="small text-danger mt-2">Bayilik belgeleri bayilik sistemi etkinleştirilmeden yayınlanamaz.</p>{/if}
			</form>
		</div></div>
		<div class="card mt-3"><div class="card-body">
			<h3 class="h6">Sürümler</h3>
			<table class="table table-sm"><thead><tr><th>Sürüm</th><th>Not</th><th>Yayın</th><th></th></tr></thead><tbody>
			{foreach $rlVersions as $v}<tr{if $v.id_version == $rlDoc.current_version} class="table-success"{/if}>
				<td><a href="?tab=edit&id={$rlDoc.id_document}&v={$v.id_version}">{$v.version|escape}</a></td><td class="small">{$v.change_note|escape}<br><code class="small">{$v.body_sha256|truncate:12:''|escape}</code></td><td class="small">{$v.published_at|default:'taslak'|escape}</td>
				<td>{if $v.id_version != $rlDoc.current_version}<form method="post"><input type="hidden" name="token" value="{$rlToken|escape}"><input type="hidden" name="rlAction" value="publish_version"><input type="hidden" name="id_document" value="{$rlDoc.id_document}"><input type="hidden" name="id_version" value="{$v.id_version}"><button class="btn btn-sm btn-outline-success" type="submit">Yayınla</button></form>{else}<span class="badge text-bg-success">geçerli</span>{/if}</td>
			</tr>{/foreach}
			</tbody></table>
		</div></div>
	</div>
</div>
{/if}

{if $rlTab == 'company'}
<div class="card"><div class="card-body">
	<p class="text-muted">Bu bilgiler belgelerde <code>{literal}{{...}}{/literal}</code> yer tutucularının yerine yazılır. <strong>Gerçek olmayan bilgi girmeyin</strong>; boş alanlar sitede "[DOLDURULMADI]" olarak işaretli görünür.</p>
	<form method="post"><input type="hidden" name="token" value="{$rlToken|escape}"><input type="hidden" name="rlAction" value="company">
		<div class="row g-2">
		{foreach $rlCompany as $ph => $f}
			<div class="col-md-6"><label class="form-label small" for="rl-{$f.key|escape}">{$f.label|escape} <code class="small">{literal}{{{/literal}{$ph|escape}{literal}}}{/literal}</code></label>
			{if $ph == 'CEREZ_LISTESI' || $ph == 'YURT_DISI_AKTARIM' || $ph == 'ADRES'}<textarea class="form-control" rows="2" id="rl-{$f.key|escape}" name="{$f.key|escape}">{$f.value|escape}</textarea>{else}<input class="form-control" id="rl-{$f.key|escape}" name="{$f.key|escape}" value="{$f.value|escape}">{/if}</div>
		{/foreach}
		</div>
		<button class="btn btn-primary mt-3" type="submit">Kaydet</button>
	</form>
</div></div>
{/if}

{if $rlTab == 'acceptances'}
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tarih</th><th>Belge</th><th>Sürüm</th><th>Kullanıcı</th><th>Bağlam</th><th>Referans</th><th>IP özeti</th></tr></thead><tbody>
{foreach $rlAcceptances as $a}<tr><td>{$a.accepted_at|escape}</td><td>{$a.slug|escape}</td><td>{$a.version|escape}</td><td>#{$a.id_user}</td><td>{$a.context|escape}</td><td>{$a.ref|escape}</td><td><code class="small">{$a.ip_hash|truncate:10:''|escape}</code></td></tr>{foreachelse}<tr><td colspan="7" class="text-muted">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}

{if $rlTab == 'consents'}
<p class="small text-muted">İzinler ekleme-yalnızca kayıttır: her verme/geri alma ayrı satırdır; en son kayıt geçerlidir.</p>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tarih</th><th>Tür</th><th>Durum</th><th>Kullanıcı / ziyaretçi</th><th>Kaynak</th></tr></thead><tbody>
{foreach $rlConsents as $c}<tr><td>{$c.date_add|escape}</td><td>{$rlConsentTypes[$c.consent_type]|default:$c.consent_type|escape}</td><td>{if $c.granted}<span class="badge text-bg-success">verildi</span>{else}<span class="badge text-bg-secondary">yok / geri alındı</span>{/if}</td><td>{if $c.id_user}#{$c.id_user}{else}<code class="small">{$c.visitor|truncate:8:''|escape}…</code>{/if}</td><td>{$c.source|escape}</td></tr>{foreachelse}<tr><td colspan="5" class="text-muted">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}
