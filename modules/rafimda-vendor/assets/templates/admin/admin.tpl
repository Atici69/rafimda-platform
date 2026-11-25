{if $flash}<div class="alert alert-{$flashType|escape}">{$flash|escape}</div>{/if}

{if $rvPending}
<form method="post" class="alert alert-warning d-flex align-items-center gap-2">
	<input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="migrate">
	<span>Bekleyen migration: <strong>{join($rvPending, ', ')|escape}</strong>. Satıcı paneli çalışmadan önce çalıştırın (yedek alın).</span>
	<button type="submit" class="btn btn-sm btn-dark ms-auto">Çalıştır</button>
</form>
{/if}

<ul class="nav nav-tabs mb-3">
	{foreach ['applications' => 'Başvurular', 'vendors' => 'Satıcılar', 'products' => 'Ürün Onayı', 'orders' => 'Satıcı Siparişleri', 'finance' => 'Finans & Ödemeler', 'plans' => 'Paketler', 'engage' => 'Soru · Yorum · Destek', 'settings' => 'Ayarlar'] as $k => $l}
	<li class="nav-item"><a class="nav-link{if $rvTab == $k} active{/if}" href="?tab={$k}">{$l}{if $k == 'applications' && $rvAppOpen|default:0 > 0} <span class="badge text-bg-warning">{$rvAppOpen}</span>{/if}{if $k == 'engage' && $rvEngTicketOpen|default:0 > 0} <span class="badge text-bg-info">{$rvEngTicketOpen}</span>{/if}</a></li>
	{/foreach}
</ul>

{if $rvTab == 'applications'}
{if $rvApp}
<p><a href="?tab=applications">← Başvurular</a></p>
<div class="row g-3">
	<div class="col-lg-7"><div class="card"><div class="card-body">
		<h2 class="h5">{$rvApp.app_no|escape} · {$rvApp.store_name|escape} <span class="badge text-bg-{if $rvApp.status == 'approved'}success{elseif $rvApp.status == 'rejected'}danger{elseif $rvApp.status == 'docs_required'}warning{else}info{/if}">{$rvAppStatuses[$rvApp.status]|escape}</span></h2>
		<table class="table table-sm"><tbody>
			<tr><th>Tür</th><td>{$rvPersonTypes[$rvApp.person_type]|default:$rvApp.person_type|escape}</td></tr>
			<tr><th>Unvan</th><td>{$rvApp.legal_name|escape}</td></tr>
			<tr><th>Yetkili</th><td>{$rvApp.contact_name|escape}</td></tr>
			<tr><th>İletişim</th><td>{$rvApp.phone|escape} · {$rvApp.email|escape}</td></tr>
			<tr><th>Vergi</th><td>{$rvApp.tax_office|escape} / {$rvApp.tax_number|escape}</td></tr>
			<tr><th>Adres</th><td>{$rvApp.address|escape} {$rvApp.district|escape} / {$rvApp.city|escape}</td></tr>
			<tr><th>Kategoriler</th><td>{foreach $rvApp.category_list as $c}<span class="badge text-bg-light me-1">{$c|escape}</span>{/foreach}</td></tr>
			<tr><th>Açıklama</th><td>{$rvApp.description|escape}</td></tr>
			<tr><th>Sözleşme / KVKK</th><td>Sürüm {$rvApp.terms_version|escape} · KVKK okundu: {$rvApp.kvkk_ack_at|default:'—'|escape}</td></tr>
			<tr><th>Başvuru</th><td>{$rvApp.date_add|escape}</td></tr>
		</tbody></table>
		<h3 class="h6">Belgeler</h3>
		{if $rvAppDocs}<ul>{foreach $rvAppDocs as $d}<li><a href="?tab=applications&doc={$d.id_doc}">{$rvDocTypes[$d.doc_type]|default:$d.doc_type|escape}: {$d.original_name|escape}</a> <small class="text-muted">({$d.mime|escape}, {($d.size/1024)|string_format:'%.0f'} KB)</small></li>{/foreach}</ul>{else}<p class="text-muted">Belge yüklenmedi.</p>{/if}
	</div></div></div>
	<div class="col-lg-5"><div class="card"><div class="card-body">
		<h3 class="h6">İnceleme</h3>
		{if $rvAppTransitions[$rvApp.status]}
		<form method="post">
			<input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="app_transition"><input type="hidden" name="id_application" value="{$rvApp.id_application}">
			<label class="form-label" for="app-to">Yeni durum</label>
			<select class="form-select mb-2" id="app-to" name="to">{foreach $rvAppTransitions[$rvApp.status] as $t}<option value="{$t}">{$rvAppStatuses[$t]|escape}</option>{/foreach}</select>
			<label class="form-label" for="app-note">Not (belge istemek ve reddetmek için zorunlu; başvuru sahibine iletilir)</label>
			<textarea class="form-control mb-2" id="app-note" name="note" rows="3"></textarea>
			<button class="btn btn-primary" type="submit">Kaydet ve bildir</button>
		</form>
		<p class="small text-muted mt-2">Onay, mağazayı etkinleştirir (satıcı paneli ve vitrin açılır).</p>
		{else}<p class="text-muted">Bu başvuru kapandı.</p>{/if}
		<h3 class="h6 mt-3">Geçmiş</h3>
		<ul class="small">{foreach $rvAppHistory as $h}<li>{$h.date_add|escape} · {$rvAppStatuses[$h.to_status]|default:$h.to_status|escape} · {$h.actor|escape}{if $h.note} — {$h.note|escape}{/if}</li>{/foreach}</ul>
	</div></div></div>
</div>
{else}
<div class="d-flex flex-wrap gap-2 mb-3">
	{foreach $rvAppStatuses as $k => $l}<a class="badge text-bg-{if $k == 'approved'}success{elseif $k == 'rejected'}danger{elseif $k == 'docs_required'}warning{else}info{/if} text-decoration-none p-2" href="?tab=applications&astatus={$k}">{$l|escape}: {$rvAppCounts[$k]|default:0}</a>{/foreach}
	<a class="badge text-bg-light text-decoration-none p-2" href="?tab=applications">Tümü</a>
</div>
<form method="get" class="row g-2 mb-3"><input type="hidden" name="tab" value="applications"><input type="hidden" name="astatus" value="{$rvAppFilter|escape}">
	<div class="col-md-4"><input class="form-control" name="q" value="{$rvQuery|escape}" placeholder="Başvuru no, mağaza, yetkili"></div><div class="col-auto"><button class="btn btn-outline-dark">Ara</button></div></form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>No</th><th>Mağaza</th><th>Tür</th><th>Yetkili</th><th>İl</th><th>Belge</th><th>Durum</th><th>Tarih</th><th></th></tr></thead><tbody>
{foreach $rvApps as $a}<tr><td>{$a.app_no|escape}</td><td>{$a.store_name|escape}</td><td>{$rvPersonTypes[$a.person_type]|default:''|escape}</td><td>{$a.contact_name|escape}</td><td>{$a.city|escape}</td><td>{$a.doc_count}</td><td>{$rvAppStatuses[$a.status]|escape}</td><td>{$a.date_add|escape}</td><td><a class="btn btn-sm btn-outline-primary" href="?tab=applications&app={$a.id_application}">İncele</a></td></tr>{foreachelse}<tr><td colspan="9" class="text-muted">Başvuru yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}
{/if}

{if $rvTab == 'engage'}
<div class="row g-3">
<div class="col-lg-6">
	<div class="card mb-3"><div class="card-body">
		<h2 class="h6">Destek talepleri</h2>
		{if $rvEngTicket}
			<p><a href="?tab=engage">← Tüm talepler</a></p>
			<p><strong>{$rvEngTicket.ticket_no|escape}</strong> · {$rvEngTicket.subject|escape} · {$rvEngCategories[$rvEngTicket.category]|default:''|escape} · {if $rvEngTicket.opener == 'vendor'}Satıcı #{$rvEngTicket.id_vendor}{else}Müşteri #{$rvEngTicket.id_user}{/if}{if $rvEngTicket.id_order} · Sipariş #{$rvEngTicket.id_order}{/if}</p>
			<ul class="small">{foreach $rvEngTicket.messages as $m}<li><strong>{$m.author|escape}</strong> {$m.date_add|escape}<br>{$m.body|escape|nl2br nofilter}</li>{/foreach}</ul>
			<form method="post"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="eng_ticket_reply"><input type="hidden" name="id_ticket" value="{$rvEngTicket.id_ticket}">
				<textarea class="form-control mb-2" name="body" rows="3" required placeholder="Yanıt (talep sahibine bildirilir)"></textarea>
				<select class="form-select mb-2" name="status">{foreach ['answered', 'waiting', 'closed'] as $st}<option value="{$st}">{$rvEngTicketStatuses[$st]|escape}</option>{/foreach}</select>
				<button class="btn btn-primary btn-sm" type="submit">Yanıtla</button></form>
		{else}
			<table class="table table-sm"><thead><tr><th>No</th><th>Kimden</th><th>Konu</th><th>Durum</th></tr></thead><tbody>
			{foreach $rvEngTickets as $t}<tr><td><a href="?tab=engage&ticket={$t.id_ticket}">{$t.ticket_no|escape}</a></td><td>{if $t.opener == 'vendor'}Satıcı{else}Müşteri{/if}</td><td>{$t.subject|escape}</td><td>{$rvEngTicketStatuses[$t.status]|escape}</td></tr>{foreachelse}<tr><td colspan="4" class="text-muted">Talep yok.</td></tr>{/foreach}
			</tbody></table>
		{/if}
	</div></div>
	<div class="card"><div class="card-body">
		<h2 class="h6">Ayarlar</h2>
		<form method="post"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="eng_settings">
			<div class="form-check"><input class="form-check-input" type="checkbox" name="qa_moderation" value="1" id="eng-qa"{if $rvEngQaMod} checked{/if}><label class="form-check-label" for="eng-qa">Satıcı yanıtları yönetici onayından sonra yayınlansın</label></div>
			<div class="form-check"><input class="form-check-input" type="checkbox" name="purchase_only" value="1" id="eng-po"{if $rvEngPurchaseOnly} checked{/if}><label class="form-check-label" for="eng-po">Ürün yorumu yalnızca teslim alınmış ürüne yapılabilsin</label></div>
			<button class="btn btn-outline-primary btn-sm mt-2" type="submit">Kaydet</button></form>
		<p class="small text-muted mt-2">Ürün soruları ve ürün yorumları "Soru-Cevap" ve "Yorumlar" modül ekranlarından onaylanır{if !$rvEngQaAvailable} (soru modülü kurulu değil){/if}{if !$rvEngReviewsAvailable} (yorum modülü kurulu değil){/if}. E-posta: {if $rvEngChannels.email == 'configured'}yapılandırıldı{else}<strong>yapılandırılmadı</strong>{/if}; SMS: altyapı yok.</p>
	</div></div>
</div>
<div class="col-lg-6">
	<div class="card mb-3"><div class="card-body">
		<h2 class="h6">Mağaza soruları (moderasyon)</h2>
		{foreach $rvEngStoreQ as $q}<div class="border rounded p-2 mb-2"><small class="text-muted">{$q.store_name|escape} · {$q.date_add|escape} · {$q.status|escape}</small><p class="mb-1">{$q.question|escape}</p>{if $q.answer}<p class="mb-1 text-primary">↳ {$q.answer|escape}</p>{/if}
			<form method="post" class="d-inline"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="eng_question"><input type="hidden" name="id" value="{$q.id_store_question}">{if $q.answer}<button class="btn btn-sm btn-success" name="to" value="published">Yayınla</button>{/if} <button class="btn btn-sm btn-outline-danger" name="to" value="rejected">Reddet</button></form></div>
		{foreachelse}<p class="text-muted">Bekleyen mağaza sorusu yok.</p>{/foreach}
	</div></div>
	<div class="card mb-3"><div class="card-body">
		<h2 class="h6">Mağaza değerlendirmeleri (moderasyon)</h2>
		{foreach $rvEngStoreR as $r}<div class="border rounded p-2 mb-2"><small class="text-muted">{$r.store_name|escape} · {$r.rating}/5 · sipariş #{$r.id_order} · doğrulanmış</small><p class="mb-1">{$r.comment|escape}</p>
			<form method="post" class="d-inline"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="eng_review"><input type="hidden" name="id" value="{$r.id_store_review}"><button class="btn btn-sm btn-success" name="to" value="approved">Onayla</button> <button class="btn btn-sm btn-outline-danger" name="to" value="rejected">Reddet</button></form></div>
		{foreachelse}<p class="text-muted">Bekleyen mağaza değerlendirmesi yok.</p>{/foreach}
	</div></div>
	<div class="card"><div class="card-body">
		<h2 class="h6">Yönetici bildirimleri</h2>
		<ul class="small mb-0">{foreach $rvEngNotifications as $n}<li>{$n.date_add|escape} · {$n.title|escape}</li>{foreachelse}<li class="text-muted">Bildirim yok.</li>{/foreach}</ul>
	</div></div>
</div>
</div>
{/if}

{if $rvTab == 'vendors'}
<div class="admin-panel p-3 mb-3">
	<div class="d-flex flex-wrap gap-2 align-items-center">
		<span class="text-muted">Satıcı girişi: <a href="{$rvPanelUrl|escape}" target="_blank" rel="noopener">{$rvPanelUrl|escape}</a></span>
		<div class="ms-auto d-flex gap-2 flex-wrap">
			{foreach $rvStatuses as $key => $label}<a class="badge text-bg-{if $key == 'pending'}warning{elseif $key == 'active'}success{elseif $key == 'suspended'}secondary{else}danger{/if} text-decoration-none p-2" href="?tab=vendors&status={$key|escape}">{$label|escape}: {$rvCounts[$key]|default:0}</a>{/foreach}
			<a class="badge text-bg-light text-decoration-none p-2" href="?tab=vendors">Tümü</a>
		</div>
	</div>
</div>
<div class="admin-panel p-3">
	<form method="get" class="row g-2 mb-3"><input type="hidden" name="tab" value="vendors">
		<div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Mağaza, e-posta ara" value="{$rvQuery|escape}"></div>
		<div class="col-md-2"><button class="btn btn-dark w-100" type="submit">Ara</button></div>
	</form>
	<div class="table-responsive"><table class="table align-middle">
		<thead><tr><th>#</th><th>Mağaza</th><th>Sahip</th><th>Ürün</th><th>Durum</th><th>Komisyon %</th><th style="min-width:250px">İşlem</th></tr></thead><tbody>
		{foreach $rvVendors as $v}<tr>
			<td>{$v.id_vendor|escape}</td>
			<td><strong>{$v.store_name|escape}</strong><br><small class="text-muted">/{$v.store_slug|escape} · {$v.city|escape}</small></td>
			<td>{$v.owner_name|escape}<br><small class="text-muted">{$v.owner_email|escape}</small></td>
			<td>{$v.product_count|escape}</td>
			<td>{$rvStatuses[$v.status]|default:$v.status|escape}{if $v.rejection_reason}<br><small class="text-muted">{$v.rejection_reason|escape}</small>{/if}</td>
			<td><form method="post" class="d-flex gap-1"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="vendor_commission"><input type="hidden" name="id_vendor" value="{$v.id_vendor|escape}">
				<input type="text" name="commission_rate" class="form-control form-control-sm" style="width:70px" value="{$v.commission_rate|escape}" title="0 = varsayılan"><button class="btn btn-sm btn-outline-dark">Kaydet</button></form></td>
			<td><form method="post" class="d-flex gap-1 flex-wrap align-items-center"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="vendor_status"><input type="hidden" name="id_vendor" value="{$v.id_vendor|escape}">
				<input type="text" name="reason" class="form-control form-control-sm" style="max-width:120px" placeholder="Red nedeni">
				{if $v.status != 'active'}<button name="to_status" value="active" class="btn btn-sm btn-success">Onayla</button>{/if}
				{if $v.status == 'active'}<button name="to_status" value="suspended" class="btn btn-sm btn-secondary">Askıya al</button>{/if}
				{if $v.status != 'rejected'}<button name="to_status" value="rejected" class="btn btn-sm btn-outline-danger">Reddet</button>{/if}</form></td>
		</tr>{foreachelse}<tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr>{/foreach}
	</tbody></table></div>
</div>
{/if}

{if $rvTab == 'products'}
<div class="admin-panel p-3">
	<div class="mb-3 d-flex gap-2">
		{foreach ['pending' => 'Bekleyen', 'approved' => 'Onaylı', 'rejected' => 'Reddedilen'] as $k => $l}<a class="btn btn-sm {if $rvModState == $k}btn-dark{else}btn-outline-dark{/if}" href="?tab=products&mod={$k}">{$l}</a>{/foreach}
	</div>
	<div class="table-responsive"><table class="table align-middle">
		<thead><tr><th>#</th><th>Ürün</th><th>Mağaza</th><th>Fiyat</th><th>Stok</th><th>Not</th><th style="min-width:260px">İşlem</th></tr></thead><tbody>
		{foreach $rvModerationQueue as $p}<tr>
			<td>{$p.id_product|escape}</td><td>{$p.product_name|escape}</td><td>{$p.store_name|escape}</td><td>{$p.price|escape}</td><td>{$p.stock|escape}</td><td>{$p.moderation_note|escape}</td>
			<td><form method="post" class="d-flex gap-1 flex-wrap"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="id_product" value="{$p.id_product|escape}">
				<input type="text" name="note" class="form-control form-control-sm" style="max-width:130px" placeholder="Red notu">
				{if $p.moderation != 'approved'}<button name="rvAction" value="product_approve" class="btn btn-sm btn-success">Onayla</button>{/if}
				{if $p.moderation != 'rejected'}<button name="rvAction" value="product_reject" class="btn btn-sm btn-outline-danger">Reddet</button>{/if}</form></td>
		</tr>{foreachelse}<tr><td colspan="7" class="text-center text-muted py-4">Bu durumda ürün yok.</td></tr>{/foreach}
	</tbody></table></div>
</div>
{/if}

{if $rvTab == 'orders'}
<div class="admin-panel p-3 mb-3 d-flex gap-4 flex-wrap">
	<div><small class="text-muted">Satıcı siparişi</small><div class="h5">{$rvTotals.orders|escape}</div></div>
	<div><small class="text-muted">Brüt satış</small><div class="h5">{$rvTotals.gross|escape} ₺</div></div>
	<div><small class="text-muted">Platform komisyonu</small><div class="h5">{$rvTotals.commission|escape} ₺</div></div>
	<div><small class="text-muted">Satıcı kazancı</small><div class="h5">{$rvTotals.earning|escape} ₺</div></div>
</div>
<div class="admin-panel p-3"><div class="table-responsive"><table class="table align-middle">
	<thead><tr><th>#</th><th>Sipariş</th><th>Mağaza</th><th>Tutar</th><th>Komisyon</th><th>Kazanç</th><th>Durum</th><th>Takip</th></tr></thead><tbody>
	{foreach $rvVendorOrders as $o}<tr><td>{$o.id_vendor_order|escape}</td><td>#{$o.reference|default:$o.id_order|escape}</td><td>{$o.store_name|escape}</td><td>{$o.subtotal|escape}</td><td>{$o.commission_amount|escape} (%{$o.commission_rate|escape})</td><td>{$o.vendor_earning|escape}</td><td>{$rvOrderStatuses[$o.status]|default:$o.status|escape}</td><td>{$o.cargo_company|escape} {$o.tracking_number|escape}</td></tr>
	{foreachelse}<tr><td colspan="8" class="text-center text-muted py-4">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}

{if $rvTab == 'finance'}
<div class="admin-panel p-3 mb-3"><div class="table-responsive"><table class="table align-middle">
	<thead><tr><th>Mağaza</th><th>Bekleyen</th><th>Ödenebilir</th><th>Toplam kazanç</th><th>Ödenen</th><th style="min-width:340px">Ödeme / Düzeltme</th></tr></thead><tbody>
	{foreach $rvVendors as $v}{assign var=b value=$rvBalances[$v.id_vendor]}<tr>
		<td>{$v.store_name|escape}</td><td>{$b.pending|escape} ₺</td><td><strong>{$b.available|escape} ₺</strong></td><td>{$b.earned|escape} ₺</td><td>{$b.paid_out|escape} ₺</td>
		<td><form method="post" class="d-flex gap-1 flex-wrap"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="id_vendor" value="{$v.id_vendor|escape}">
			<input type="text" name="amount" class="form-control form-control-sm" style="width:90px" placeholder="Tutar"><input type="text" name="reference" class="form-control form-control-sm" style="width:110px" placeholder="Dekont/ref"><input type="text" name="note" class="form-control form-control-sm" style="width:130px" placeholder="Not">
			<button name="rvAction" value="payout" class="btn btn-sm btn-success" onclick="return confirm('Ödeme kaydı oluşturulacak ve geri alınamaz (ters kayıtla düzeltilir). Emin misiniz?')">Ödeme kaydet</button>
			<button name="rvAction" value="adjustment" class="btn btn-sm btn-outline-secondary" title="Not zorunlu; negatif tutar düşer">Düzeltme</button></form></td>
	</tr>{foreachelse}<tr><td colspan="6" class="text-center text-muted py-4">Satıcı yok.</td></tr>{/foreach}
</tbody></table></div></div>
<div class="admin-panel p-3"><h2 class="h6">Son ödemeler</h2><table class="table"><thead><tr><th>#</th><th>Mağaza</th><th>Tutar</th><th>Referans</th><th>Tarih</th></tr></thead><tbody>
	{foreach $rvPayouts as $p}<tr><td>{$p.id_payout|escape}</td><td>{$p.store_name|escape}</td><td>{$p.amount|escape} ₺</td><td>{$p.reference|escape}</td><td>{$p.date_add|escape}</td></tr>{foreachelse}<tr><td colspan="5" class="text-muted">Ödeme yok.</td></tr>{/foreach}
</tbody></table></div>
{/if}

{if $rvTab == 'plans'}
<div class="admin-panel p-3 mb-3"><h5>Paketler</h5>
	<p class="text-muted small">Ödeme otomasyonu yoktur: paketi elle atarsınız. Limit boşsa sınırsızdır. Süresi dolan paket okuma anında "free" paketine düşer (veri silinmez).</p>
	{foreach $rvPlans as $p}
	<form method="post" class="row g-2 align-items-center mb-2 border-bottom pb-2"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="plan_save"><input type="hidden" name="code" value="{$p.code|escape}"><input type="hidden" name="sort_order" value="{$p.sort_order|escape}">
		<div class="col-auto" style="min-width:90px"><code>{$p.code|escape}</code></div>
		<div class="col-auto"><input class="form-control form-control-sm" name="name" value="{$p.name|escape}" aria-label="Ad"></div>
		<div class="col-auto"><input class="form-control form-control-sm" style="width:90px" name="price_monthly" value="{$p.price_monthly|escape}" aria-label="Aylık ücret"></div>
		<div class="col-auto"><input class="form-control form-control-sm" style="width:90px" name="max_products" value="{$p.max_products|escape}" placeholder="ürün ∞" aria-label="Ürün limiti"></div>
		<div class="col-auto"><input class="form-control form-control-sm" style="width:90px" name="max_xml_connections" value="{$p.max_xml_connections|escape}" placeholder="XML ∞" aria-label="XML limiti"></div>
		<div class="col-auto"><input class="form-control form-control-sm" style="width:90px" name="max_marketplace_accounts" value="{$p.max_marketplace_accounts|escape}" placeholder="pazaryeri ∞" aria-label="Pazaryeri limiti"></div>
		<div class="col-auto"><label class="small"><input type="checkbox" name="active" value="1"{if $p.active} checked{/if}> aktif</label></div>
		<div class="col-auto"><button class="btn btn-sm btn-dark" type="submit">Kaydet</button></div>
	</form>
	{/foreach}
	<form method="post" class="row g-2 mt-2"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="plan_save">
		<div class="col-auto"><input class="form-control form-control-sm" name="code" placeholder="kod (örn. pro)" required></div><div class="col-auto"><input class="form-control form-control-sm" name="name" placeholder="Ad" required></div>
		<div class="col-auto"><input class="form-control form-control-sm" name="price_monthly" placeholder="Aylık ₺" value="0"></div><div class="col-auto"><input class="form-control form-control-sm" name="max_products" placeholder="Ürün limiti"></div>
		<div class="col-auto"><input class="form-control form-control-sm" name="max_xml_connections" placeholder="XML limiti"></div><div class="col-auto"><input class="form-control form-control-sm" name="max_marketplace_accounts" placeholder="Pazaryeri limiti"></div>
		<input type="hidden" name="active" value="1"><div class="col-auto"><button class="btn btn-sm btn-outline-dark" type="submit">Yeni paket</button></div></form>
</div>
<div class="admin-panel p-3"><h5>Satıcıya paket ata</h5>
	<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Satıcı</th><th>Mevcut</th><th>Ata</th></tr></thead><tbody>
	{foreach $rvVendors as $v}<tr><td>{$v.store_name|escape} <span class="text-muted">#{$v.id_vendor|escape}</span></td><td><code>{$v.plan_code|escape}</code>{if $v.plan_expires_at} <small class="text-muted">→ {$v.plan_expires_at|escape}</small>{/if}</td>
		<td><form method="post" class="d-flex gap-1 flex-wrap"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="plan_assign"><input type="hidden" name="id_vendor" value="{$v.id_vendor|escape}">
			<select name="plan_code" class="form-select form-select-sm" style="width:auto">{foreach $rvPlans as $p}{if $p.active}<option value="{$p.code|escape}"{if $p.code == $v.plan_code} selected{/if}>{$p.name|escape}</option>{/if}{/foreach}</select>
			<input type="date" name="expires" class="form-control form-control-sm" style="width:150px" title="Bitiş (boş = süresiz)"><input name="note" class="form-control form-control-sm" style="width:160px" placeholder="Not"><button class="btn btn-sm btn-dark" type="submit">Ata</button></form></td></tr>{/foreach}
	</tbody></table></div>
</div>
{/if}

{if $rvTab == 'settings'}
<div class="admin-panel p-3" style="max-width:640px"><form method="post"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="settings">
	<label class="form-label">Varsayılan komisyon oranı (%)</label><input type="text" name="default_commission" class="form-control mb-3" value="{$rvDefaultCommission|escape}">
	<label class="form-label">Teslimattan sonra ödemeye hazır olma süresi (gün)</label><input type="text" name="hold_days" class="form-control mb-3" value="{$rvHoldDays|escape}">
	<div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="m" name="moderation" value="1"{if $rvModeration} checked{/if}><label class="form-check-label" for="m">Yeni satıcı ürünleri yönetici onayı beklesin (önerilir)</label></div>
	<div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="a" name="auto_approve" value="1"{if $rvAutoApprove} checked{/if}><label class="form-check-label" for="a">Satıcı başvurularını otomatik onayla (önerilmez)</label></div>
	<button class="btn btn-dark" type="submit">Kaydet</button></form></div>
{/if}
