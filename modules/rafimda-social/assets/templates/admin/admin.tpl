{if $rsoFlash}<div class="alert alert-{$rsoFlash.type|escape}">{$rsoFlash.message|escape}</div>{/if}
<div class="alert {if $rsoSettings.publish}alert-danger{else}alert-info{/if}">
	{if $rsoSettings.publish}<strong>Otomatik yayın AÇIK</strong> — yalnızca bağlı hesap ve uygulanmış resmî API ile yayın yapılır.{else}<strong>Otomatik yayın kapalı.</strong> İçerik üretimi, önizleme, onay ve takvim çalışır; zamanı gelen içerik kontrol edilir ama hiçbir hesaba paylaşılmaz.{/if}
	Resmî API yayıncıları (Instagram / TikTok / YouTube) henüz uygulanmadı. Saatler <strong>Türkiye saatidir</strong> (şu an {$rsoNowTr}).
	{if !$rsoFfmpeg}<br>Sunucuda ffmpeg yok: video biçimlerinde kare dizisi (storyboard) üretilir.{/if}
</div>
<ul class="nav nav-tabs mb-3">
	{foreach ['calendar' => 'Takvim', 'create' => 'İçerik oluştur', 'queue' => 'Kuyruk ve onay', 'accounts' => 'Hesaplar', 'reports' => 'Raporlar', 'settings' => 'Ayarlar'] as $k => $l}
	<li class="nav-item"><a class="nav-link{if $rsoTab == $k} active{/if}" href="?tab={$k}">{$l}{if $k == 'queue' && $rsoReport.counts.pending_approval|default:0} <span class="badge text-bg-warning">{$rsoReport.counts.pending_approval}</span>{/if}</a></li>
	{/foreach}
</ul>

{if $rsoPost}
<p><a href="?tab=queue">← Kuyruk</a></p>
<div class="row g-3">
	<div class="col-lg-5"><div class="card"><div class="card-body">
		<h2 class="h6">Önizleme · {$rsoFormats[$rsoPost.format][0]|escape}</h2>
		{if $rsoPost.media_list.type|default:'' == 'video'}<video src="{$rsoPost.media_urls[0]|escape}" controls muted playsinline style="width:100%;max-height:520px;background:#000;border-radius:10px"></video>
		{else}<div class="d-flex flex-wrap gap-2">{foreach $rsoPost.media_urls as $u}<img src="{$u|escape}" alt="Önizleme" style="max-width:{if $rsoPost.media_list.type|default:'' == 'storyboard'}31%{else}100%{/if};border-radius:10px;border:1px solid #ddd">{/foreach}</div>{if $rsoPost.media_list.type|default:'' == 'storyboard'}<p class="small text-muted mt-1">Video kareleri (storyboard)</p>{/if}{/if}
	</div></div></div>
	<div class="col-lg-7"><div class="card"><div class="card-body">
		<p><span class="badge text-bg-secondary">{$rsoPlatforms[$rsoPost.platform]|escape}</span> <span class="badge text-bg-{if $rsoPost.status == 'ready'}primary{elseif $rsoPost.status == 'published'}success{elseif $rsoPost.status == 'pending_approval' || $rsoPost.status == 'on_hold'}warning{elseif $rsoPost.status == 'failed'}danger{else}secondary{/if}">{$rsoStatuses[$rsoPost.status]|escape}</span>
			{if $rsoPost.scheduled_at} · {$rsoPost.scheduled_at|truncate:16:''|escape} (TR){/if} · Fiyat anlık görüntü: {$rsoPost.price_snapshot|string_format:'%.2f'} TL</p>
		{if $rsoPost.last_error}<div class="alert alert-warning small">{$rsoPost.last_error|escape}</div>{/if}
		<form method="post"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="edit"><input type="hidden" name="id_post" value="{$rsoPost.id_post}">
			<label class="form-label small" for="rso-cap">Metin</label><textarea class="form-control mb-2" id="rso-cap" name="caption" rows="7">{$rsoPost.caption|escape}</textarea>
			<label class="form-label small" for="rso-tag">Hashtag</label><input class="form-control mb-2" id="rso-tag" name="hashtags" value="{$rsoPost.hashtags|escape}">
			<button class="btn btn-outline-primary btn-sm" type="submit">Metni kaydet</button></form>
		<form method="post" class="d-flex gap-2 mt-2"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="schedule"><input type="hidden" name="id_post" value="{$rsoPost.id_post}">
			<input class="form-control form-control-sm" type="datetime-local" name="when" value="{$rsoPost.scheduled_at|default:''|replace:' ':'T'|truncate:16:''|escape}"><button class="btn btn-outline-secondary btn-sm" type="submit">Saati ayarla</button></form>
		<div class="d-flex gap-2 mt-2">
			{if $rsoPost.status == 'pending_approval'}
			<form method="post"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="approve"><input type="hidden" name="id_post" value="{$rsoPost.id_post}"><button class="btn btn-success btn-sm" type="submit">Onayla</button></form>
			<form method="post" class="d-flex gap-1"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="reject"><input type="hidden" name="id_post" value="{$rsoPost.id_post}"><input class="form-control form-control-sm" name="note" placeholder="Ret nedeni"><button class="btn btn-outline-danger btn-sm" type="submit">Reddet</button></form>
			{/if}
			{if $rsoPost.status != 'published' && $rsoPost.status != 'cancelled'}<form method="post"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="cancel"><input type="hidden" name="id_post" value="{$rsoPost.id_post}"><button class="btn btn-outline-dark btn-sm" type="submit">Yayını iptal et</button></form>{/if}
		</div>
		<h3 class="h6 mt-3">Geçmiş</h3><ul class="small">{foreach $rsoPostEvents as $e}<li class="{if $e.is_error}text-danger{/if}">{$e.date_add|escape} · {$e.event|escape} — {$e.detail|escape}</li>{/foreach}</ul>
	</div></div></div>
</div>
{elseif $rsoTab == 'calendar'}
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
	{foreach ['day' => 'Gün', 'week' => 'Hafta', 'month' => 'Ay'] as $k => $l}<a class="btn btn-sm {if $rsoView == $k}btn-primary{else}btn-outline-primary{/if}" href="?tab=calendar&view={$k}&d={$rsoAnchor}">{$l}</a>{/foreach}
	<a class="btn btn-sm btn-outline-secondary" href="?tab=calendar&view={$rsoView}&d={$rsoPrev}">‹ Önceki</a><strong>{$rsoAnchor|escape}</strong><a class="btn btn-sm btn-outline-secondary" href="?tab=calendar&view={$rsoView}&d={$rsoNext}">Sonraki ›</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Zaman (TR)</th><th>Platform</th><th>Biçim</th><th>Ürün</th><th>Durum</th><th></th></tr></thead><tbody>
{foreach $rsoCalendar as $c}<tr><td>{$c.scheduled_at|truncate:16:''|escape}</td><td>{$rsoPlatforms[$c.platform]|escape}</td><td>{$rsoFormats[$c.format][0]|escape}</td><td>{$c.product_name|escape}</td><td>{$rsoStatuses[$c.status]|escape}</td><td><a href="?tab=queue&post={$c.id_post}">Aç</a></td></tr>
{foreachelse}<tr><td colspan="6" class="text-muted">Bu aralıkta planlı içerik yok.</td></tr>{/foreach}
</tbody></table></div></div>
{elseif $rsoTab == 'create'}
<div class="row g-3">
<div class="col-lg-6"><div class="card"><div class="card-body">
	<h2 class="h6">Üründen içerik oluştur</h2>
	<form method="post"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="create">
		<label class="form-label small" for="rso-p">Ürün</label><select class="form-select mb-2" id="rso-p" name="id_product">{foreach $rsoProducts as $p}<option value="{$p.id_product}">{$p.product_name|escape} — {$p.price|string_format:'%.2f'} TL (stok {$p.stock|string_format:'%d'})</option>{/foreach}</select>
		<div class="mb-2">{foreach $rsoFormats as $k => $f}<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="formats[]" value="{$k}" id="f-{$k}"><label class="form-check-label" for="f-{$k}">{$f[0]|escape}</label></div>{/foreach}</div>
		<label class="form-label small" for="rso-when">Yayın zamanı (Türkiye saati, boş = taslak)</label><input class="form-control mb-2" type="datetime-local" id="rso-when" name="when">
		<button class="btn btn-primary" type="submit">Oluştur ve önizle</button>
		<p class="small text-muted mt-2">Metin yalnızca ürünün gerçek alanlarından (ad, açıklama, marka, fiyat, satıcı, bağlantı) oluşturulur; indirim yalnızca üründe gerçek eski fiyat varsa yazılır.</p>
	</form>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-body">
	<h2 class="h6">Otomatik ürün seçimiyle planla</h2>
	<form method="post"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="auto">
		<select class="form-select mb-2" name="rule">{foreach $rsoSelections as $k => $l}<option value="{$k}">{$l|escape}</option>{/foreach}</select>
		<select class="form-select mb-2" name="format">{foreach $rsoFormats as $k => $f}<option value="{$k}">{$f[0]|escape}</option>{/foreach}</select>
		<div class="d-flex gap-2 mb-2"><input class="form-control" type="date" name="start_day" value="{$rsoTomorrow}"><input class="form-control" type="time" name="time" value="12:00"><input class="form-control" type="number" name="count" value="3" min="1" max="14"></div>
		<button class="btn btn-outline-primary" type="submit">Günlük planla</button>
		<p class="small text-muted mt-2">Stoksuz/pasif ürünler ve tekrar engeline takılan ürünler atlanır.</p>
	</form>
</div></div></div>
</div>
{elseif $rsoTab == 'queue'}
<form class="d-flex gap-2 mb-2" method="get"><input type="hidden" name="tab" value="queue"><select class="form-select form-select-sm" style="max-width:240px" name="status"><option value="">Tüm durumlar</option>{foreach $rsoStatuses as $k => $l}<option value="{$k}">{$l|escape} ({$rsoReport.counts[$k]|default:0})</option>{/foreach}</select><button class="btn btn-sm btn-outline-dark">Süz</button></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>#</th><th>Sahip</th><th>Ürün</th><th>Biçim</th><th>Zaman (TR)</th><th>Durum</th><th></th></tr></thead><tbody>
{foreach $rsoList as $s}<tr><td>{$s.id_post}</td><td>{if $s.owner_type == 'vendor'}{$s.store_name|default:'Satıcı'|escape}{else}Rafımda{/if}</td><td>{$s.product_name|escape}</td><td>{$rsoFormats[$s.format][0]|escape}</td><td>{$s.scheduled_at|default:'—'|truncate:16:''|escape}</td><td>{$rsoStatuses[$s.status]|escape}{if $s.last_error}<br><small class="text-muted">{$s.last_error|truncate:80|escape}</small>{/if}</td><td><a class="btn btn-sm btn-outline-primary" href="?tab=queue&post={$s.id_post}">Aç</a></td></tr>
{foreachelse}<tr><td colspan="7" class="text-muted">İçerik yok.</td></tr>{/foreach}
</tbody></table></div></div>
{elseif $rsoTab == 'accounts'}
<div class="row g-3">{foreach $rsoAccounts as $pl => $a}
<div class="col-md-4"><div class="card h-100"><div class="card-body">
	<h2 class="h6">Rafımda · {$a.label|escape}</h2>
	<p class="small">Durum: {if $a.status == 'connected'}<span class="badge text-bg-success">bağlı</span>{elseif $a.status == 'error'}<span class="badge text-bg-danger">hata</span>{else}<span class="badge text-bg-secondary">bağlı değil</span>{/if}{if $a.last_error}<br><span class="text-danger">{$a.last_error|escape}</span>{/if}</p>
	<p class="small text-muted">{$a.publisher|escape} · yayın: {if $a.ready}hazır{else}henüz uygulanmadı{/if} · uygulama kimliği: {if $a.app_configured}var{else}yapılandırılmadı{/if}</p>
	<form method="post" class="d-inline"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="connect"><input type="hidden" name="platform" value="{$pl}"><button class="btn btn-sm btn-primary" type="submit">Resmî OAuth ile bağla</button></form>
	{if $a.status != 'not_connected'}<form method="post" class="d-inline"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="disconnect"><input type="hidden" name="platform" value="{$pl}"><button class="btn btn-sm btn-outline-danger" type="submit">Bağlantıyı kaldır</button></form>{/if}
</div></div></div>
{/foreach}</div>
<p class="small text-muted mt-2">Hesapları hesap sahibi kendisi yetkilendirir; şifre istenmez. Token şifreli saklanır ve kayıtlara yazılmaz.</p>
{elseif $rsoTab == 'reports'}
<div class="card"><div class="card-body">
	<h2 class="h6">İçerik durumu</h2>
	<p>{foreach $rsoStatuses as $k => $l}<span class="badge text-bg-light me-1">{$l|escape}: {$rsoReport.counts[$k]|default:0}</span>{/foreach}</p>
	<h2 class="h6">Platform istatistikleri</h2>
	{if $rsoReport.has_metrics}<p>Görüntülenme {$rsoReport.metrics.views} · Erişim {$rsoReport.metrics.reach} · Beğeni {$rsoReport.metrics.likes} · Yorum {$rsoReport.metrics.comments} · Paylaşım {$rsoReport.metrics.shares}</p>
	{else}<p class="text-muted">Veri yok: istatistikler yalnızca resmî API bağlantısı kurulup yayın yapıldığında platformdan alınır. Tahmini veya örnek rakam gösterilmez.</p>{/if}
</div></div>
{elseif $rsoTab == 'settings'}
<div class="row g-3"><div class="col-lg-7"><div class="card"><div class="card-body">
	<form method="post"><input type="hidden" name="token" value="{$rsoToken|escape}"><input type="hidden" name="rsoAction" value="settings">
		<div class="row g-2 mb-2"><div class="col-md-6"><label class="form-label small" for="rs-rd">Tekrar paylaşım engeli (gün)</label><input class="form-control" id="rs-rd" type="number" name="repeat_days" value="{$rsoSettings.repeat_days|escape}" min="0" max="365"></div>
			<div class="col-md-6"><label class="form-label small" for="rs-vl">Satıcı aylık içerik limiti (0 = sınırsız)</label><input class="form-control" id="rs-vl" type="number" name="vendor_limit" value="{$rsoSettings.vendor_limit|escape}" min="0"></div></div>
		<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="vendor_approval" value="1" id="rs-va"{if $rsoSettings.vendor_approval} checked{/if}><label class="form-check-label" for="rs-va">Satıcı içerikleri yönetici onayından sonra yayına hazır olsun</label></div>
		<h3 class="h6">Resmî uygulama kimlikleri (client ID)</h3>
		{foreach $rsoPlatforms as $k => $l}<label class="form-label small" for="rs-c{$k}">{$l|escape}</label><input class="form-control mb-2" id="rs-c{$k}" name="client_{$k}" value="{$rsoSettings.client_ids[$k]|escape}" autocomplete="off">{/foreach}
		<p class="small text-muted">Uygulama sırları (client secret) ve token'lar arayüze yazılmaz; gerçek entegrasyon aşamasında şifreli saklanacaktır.</p>
		<div class="border border-danger rounded p-2 mb-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="publish" value="1" id="rs-pub"{if $rsoSettings.publish} checked{/if}><label class="form-check-label" for="rs-pub"><strong>Otomatik yayın</strong></label></div>
			<input class="form-control mt-1" name="publish_confirm" autocomplete="off" placeholder="Açmak için yazın: OTOMATIK YAYINI ACIYORUM"><p class="small text-danger mb-0">Yalnızca resmî API yetkileri ve işletme sahibinin açık onayı tamamlandıktan sonra.</p></div>
		<button class="btn btn-primary" type="submit">Kaydet</button>
	</form>
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-body"><h2 class="h6">İşlem ve hata kayıtları</h2>
	<ul class="small mb-0">{foreach $rsoLogs as $e}<li class="{if $e.is_error}text-danger{/if}">{$e.date_add|escape} · {if $e.id_post}#{$e.id_post} · {/if}{$e.event|escape} — {$e.detail|truncate:140|escape}</li>{foreachelse}<li class="text-muted">Kayıt yok.</li>{/foreach}</ul>
	<p class="small text-muted mt-2">Cron: <code>php modules/rafimda-social/cron.php</code> (5 dakikada bir önerilir).</p>
</div></div></div></div>
{/if}
