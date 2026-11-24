{if $flash}<div class="alert alert-{$flashType|escape}">{$flash|escape}</div>{/if}
{if !$rdEnabled}
<div class="card mb-3 border-warning">
	<div class="card-body">
		<h2 class="h5 mb-2"><span class="badge text-bg-warning me-2">PASİF</span>Bayilik sistemi: {$rdPassiveLabel|escape}</h2>
		<p class="mb-2">İlk lansmanda bayilik ve alt bayilik kapalıdır: bayi başvurusu, bayi hesabı, alt bayi oluşturma, bayi satış ve komisyon kayıtları <strong>çalışmaz</strong>; bayi sayfaları ziyaretçiye 404 döner ve ana sayfada bayilik bağlantısı gösterilmez. Altyapı, tablolar ve mevcut kayıtlar <strong>korunur</strong>.</p>
		<p class="text-muted small mb-3">Korunan kayıtlar: {$rdCounts.pending|default:0} bekleyen, {$rdCounts.active|default:0} aktif, {$rdCounts.suspended|default:0} askıda, {$rdCounts.rejected|default:0} reddedilmiş bayi kaydı.</p>
		<form method="post" class="row g-2 align-items-end">
			<input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="system_switch"><input type="hidden" name="to" value="1">
			<div class="col-md-6"><label class="form-label small" for="rd-confirm">Yalnızca işletme sahibinin açık yazılı onayından sonra etkinleştirin. Onay ifadesi: <code>{$rdConfirmPhrase|escape}</code></label>
			<input class="form-control" id="rd-confirm" name="confirm_phrase" autocomplete="off"></div>
			<div class="col-md-3"><button type="submit" class="btn btn-outline-danger">Bayiliği etkinleştir</button></div>
		</form>
	</div>
</div>
{else}
<form method="post" class="alert alert-secondary d-flex align-items-center gap-2">
	<input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="system_switch"><input type="hidden" name="to" value="0">
	<span>Bayilik sistemi <strong>etkin</strong>.</span><button type="submit" class="btn btn-sm btn-outline-dark ms-auto">Pasife al (veriler korunur)</button>
</form>
{if $rdPending}
<form method="post" class="alert alert-warning d-flex align-items-center gap-2">
	<input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="migrate">
	<span>Bekleyen migration: <strong>{implode(', ', $rdPending)|escape}</strong>. Bayi sistemi çalışmadan önce çalıştırın (yedek alın).</span>
	<button type="submit" class="btn btn-sm btn-dark ms-auto">Çalıştır</button>
</form>
{/if}
<ul class="nav nav-tabs mb-3">
	{foreach ['applications' => 'Başvurular', 'dealers' => 'Bayiler', 'plans' => 'Paketler', 'subscriptions' => 'Abonelikler', 'commissions' => 'Komisyonlar', 'reports' => 'Raporlar', 'settings' => 'Ayarlar'] as $k => $l}
	<li class="nav-item"><a class="nav-link{if $rdTab == $k} active{/if}" href="?tab={$k}">{$l}{if $k == 'applications' && $rdCounts.pending} <span class="badge text-bg-warning">{$rdCounts.pending|escape}</span>{/if}</a></li>
	{/foreach}
</ul>

{if $rdTab == 'applications' || $rdTab == 'dealers'}
<div class="admin-panel p-3 mb-3">
	<span class="text-muted">Bayi girişi: <a href="{$rdPanelUrl|escape}" target="_blank" rel="noopener">{$rdPanelUrl|escape}</a></span>
	{if $rdTab == 'dealers'}<div class="d-flex gap-2 flex-wrap mt-2">
		{foreach $rdStatuses as $key => $label}<a class="badge text-bg-secondary text-decoration-none p-2" href="?tab=dealers&status={$key|escape}">{$label|escape}: {$rdCounts[$key]|default:0}</a>{/foreach}
		<a class="badge text-bg-light text-decoration-none p-2" href="?tab=dealers">Tümü</a>
	</div>
	<form method="get" class="row g-2 mt-2"><input type="hidden" name="tab" value="dealers"><div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Firma, e-posta, kod ara" value="{$rdQuery|escape}"></div><div class="col-md-2"><button class="btn btn-dark w-100" type="submit">Ara</button></div></form>{/if}
</div>
<div class="admin-panel p-3"><div class="table-responsive"><table class="table align-middle">
	<thead><tr><th>#</th><th>Bayi</th><th>Seviye</th><th>Durum</th><th>Oranlar %</th><th style="min-width:280px">İşlem</th></tr></thead><tbody>
	{foreach $rdDealers as $d}<tr>
		<td>{$d.id_dealer|escape}</td>
		<td><strong>{$d.company_name|escape}</strong><br><small class="text-muted">{$d.contact_name|escape} · {$d.email|escape} · {$d.phone|escape} · {$d.city|escape}<br>Vergi: {$d.tax_office|escape} {$d.tax_number|escape} · Kod: {$d.referral_code|escape}</small>{if $d.note}<br><small>{$d.note|escape}</small>{/if}</td>
		<td>{if $d.id_parent > 0}Alt bayi<br><small class="text-muted">Üst: {$d.parent_name|escape}</small>{else}Bayi{/if}</td>
		<td>{$rdStatuses[$d.status]|default:$d.status|escape}{if $d.paused_by_parent} <small>(üst bayi durdurdu)</small>{/if}{if $d.rejection_reason}<br><small class="text-muted">{$d.rejection_reason|escape}</small>{/if}</td>
		<td><form method="post" class="d-flex gap-1 flex-wrap"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="dealer_rates"><input type="hidden" name="id_dealer" value="{$d.id_dealer|escape}">
			<input type="text" name="commission_rate" value="{$d.commission_rate|escape}" class="form-control form-control-sm" style="width:70px" title="Komisyon %"><input type="text" name="parent_share_rate" value="{$d.parent_share_rate|escape}" class="form-control form-control-sm" style="width:70px" title="Üst bayi payı %"><button class="btn btn-sm btn-outline-dark" type="submit">Kaydet</button></form>
			<small class="text-muted">Komisyon / üst bayi payı (0 = paket/varsayılan)</small></td>
		<td>
			<form method="post" class="d-flex gap-1 flex-wrap mb-1"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="dealer_status"><input type="hidden" name="id_dealer" value="{$d.id_dealer|escape}">
				<input type="text" name="reason" class="form-control form-control-sm" placeholder="Gerekçe (red için)" style="width:140px">
				{if $d.status != 'active'}<button class="btn btn-sm btn-success" name="to_status" value="active" type="submit">Onayla</button>{/if}
				{if $d.status == 'active'}<button class="btn btn-sm btn-warning" name="to_status" value="suspended" type="submit">Askıya al</button>{/if}
				{if $d.status != 'rejected'}<button class="btn btn-sm btn-outline-danger" name="to_status" value="rejected" type="submit">Reddet</button>{/if}
			</form>
			{if $rdTab == 'dealers' && $d.status == 'active'}<form method="post" class="d-flex gap-1"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="attach_customer"><input type="hidden" name="id_dealer" value="{$d.id_dealer|escape}"><input type="email" name="user_email" class="form-control form-control-sm" placeholder="Müşteri e-postası bağla" style="width:190px"><button class="btn btn-sm btn-outline-dark" type="submit">Bağla</button></form>{/if}
		</td></tr>{foreachelse}<tr><td colspan="6" class="text-muted">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}

{if $rdTab == 'plans'}
<div class="admin-panel p-3 mb-3"><h5>Paketler</h5><p class="text-muted">Abonelik ücreti sistemde tahsil edilmez; paket yalnızca kayıt, komisyon oranı ve alt bayi kontenjanını belirler.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Kod</th><th>Ad</th><th>Aylık ₺</th><th>Komisyon %</th><th>Alt bayi</th><th>Aktif</th><th></th></tr></thead><tbody>
{foreach $rdPlans as $p}<tr>
<td>{$p.code|escape}</td><td><input form="pf_{$p.code|escape}" class="form-control form-control-sm" name="name" value="{$p.name|escape}"></td><td><input form="pf_{$p.code|escape}" class="form-control form-control-sm" name="monthly_price" value="{$p.monthly_price|escape}" style="width:90px"></td><td><input form="pf_{$p.code|escape}" class="form-control form-control-sm" name="commission_rate" value="{$p.commission_rate|escape}" style="width:80px"></td><td><input form="pf_{$p.code|escape}" class="form-control form-control-sm" name="max_sub_dealers" value="{$p.max_sub_dealers|escape}" style="width:80px"></td><td><input form="pf_{$p.code|escape}" type="checkbox" name="active" value="1" {if $p.active}checked{/if}></td>
<td><form id="pf_{$p.code|escape}" method="post"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="plan_save"><input type="hidden" name="code" value="{$p.code|escape}"><button class="btn btn-sm btn-dark" type="submit">Kaydet</button></form></td></tr>{/foreach}
</tbody></table></div>
<h6 class="mt-3">Yeni paket</h6>
<form method="post" class="row g-2"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="plan_save">
<div class="col-md-2"><input class="form-control" name="code" placeholder="kod"></div><div class="col-md-3"><input class="form-control" name="name" placeholder="Ad"></div><div class="col-md-2"><input class="form-control" name="monthly_price" placeholder="Aylık ₺"></div><div class="col-md-2"><input class="form-control" name="commission_rate" placeholder="Komisyon %"></div><div class="col-md-2"><input class="form-control" name="max_sub_dealers" placeholder="Alt bayi"></div><input type="hidden" name="active" value="1"><div class="col-md-1"><button class="btn btn-dark" type="submit">Ekle</button></div></form></div>
{/if}

{if $rdTab == 'subscriptions'}
<div class="admin-panel p-3 mb-3"><h5>Paket ata</h5>
<form method="post" class="row g-2"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="plan_assign">
<div class="col-md-2"><input class="form-control" name="id_dealer" placeholder="Bayi #"></div>
<div class="col-md-3"><select class="form-select" name="plan_code">{foreach $rdPlans as $p}<option value="{$p.code|escape}">{$p.name|escape}</option>{/foreach}</select></div>
<div class="col-md-3"><input class="form-control" type="date" name="expires" title="Bitiş (boş = süresiz)"></div>
<div class="col-md-3"><input class="form-control" name="note" placeholder="Not"></div><div class="col-md-1"><button class="btn btn-dark" type="submit">Ata</button></div></form></div>
<div class="admin-panel p-3"><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Bayi</th><th>Paket</th><th>Durum</th><th>Başlangıç</th><th>Bitiş</th><th>Tarih</th><th></th></tr></thead><tbody>
{foreach $rdRequests as $r}<tr><td>{$r.id_sub|escape}</td><td>{$r.company_name|escape} (#{$r.id_dealer|escape})</td><td>{$r.plan_code|escape}</td><td>{$r.status|escape}</td><td>{$r.starts_at|escape}</td><td>{$r.expires_at|escape}</td><td>{$r.date_add|escape}</td>
<td>{if $r.status == 'requested'}<form method="post"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="plan_assign"><input type="hidden" name="id_dealer" value="{$r.id_dealer|escape}"><input type="hidden" name="plan_code" value="{$r.plan_code|escape}"><button class="btn btn-sm btn-success" type="submit">Talebi onayla (süresiz)</button></form>{/if}</td></tr>{foreachelse}<tr><td colspan="8" class="text-muted">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}

{if $rdTab == 'commissions'}
<div class="admin-panel p-3"><p class="text-muted">Komisyonlar yalnızca hesaplanır; ödeme/muhasebe kaydı yoktur.</p><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Bayi</th><th>Sipariş</th><th>Tür</th><th>Tutar</th><th>Oran</th><th>Komisyon</th><th>Durum</th><th></th></tr></thead><tbody>
{foreach $rdCommissions as $k}<tr><td>{$k.id_commission|escape}</td><td>{$k.company_name|escape}</td><td>#{$k.id_order|escape}</td><td>{if $k.type == 'override'}Alt bayi payı{else}Doğrudan{/if}</td><td>{$k.order_total|escape}</td><td>%{$k.rate|escape}</td><td>{$k.amount|escape}</td><td>{$rdCommStatuses[$k.status]|default:$k.status|escape}</td>
<td><form method="post" class="d-flex gap-1"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="commission_set"><input type="hidden" name="id_commission" value="{$k.id_commission|escape}">{foreach $rdCommStatuses as $sk => $sl}{if $sk != $k.status}<button class="btn btn-sm btn-outline-dark" name="to_status" value="{$sk|escape}" type="submit">{$sl|escape}</button>{/if}{/foreach}</form></td></tr>{foreachelse}<tr><td colspan="9" class="text-muted">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}

{if $rdTab == 'reports'}
<div class="admin-panel p-3"><h5>Genel özet</h5><ul>
<li>Bayi: {foreach $rdStatuses as $k => $l}{$l|escape} {$rdPlatform.dealers[$k]|default:0|escape} · {/foreach}</li>
<li>Bayilere bağlı müşteri: {$rdPlatform.customers|escape}</li><li>Bayi kaynaklı satış (iptal hariç): {$rdPlatform.sales|escape} ₺</li>
<li>Bekleyen komisyon: {$rdPlatform.pending|escape} ₺ · Onaylı komisyon: {$rdPlatform.approved|escape} ₺</li></ul></div>
{/if}

{if $rdTab == 'settings'}
<div class="admin-panel p-3"><form method="post" class="row g-2"><input type="hidden" name="token" value="{$rdToken|escape}"><input type="hidden" name="rdAction" value="settings">
<div class="col-md-4"><label class="form-label">Varsayılan komisyon % (bayiye ve pakete özel oran yoksa)</label><input class="form-control" name="default_commission" value="{$rdDefaultRate|escape}"></div>
<div class="col-12"><button class="btn btn-dark" type="submit">Kaydet</button></div></form></div>
{/if}
{/if}
