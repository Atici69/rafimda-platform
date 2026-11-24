{if $flash}<div class="alert alert-{$flashType|escape}">{$flash|escape}</div>{/if}

{if $rvPending}
<form method="post" class="alert alert-warning d-flex align-items-center gap-2">
	<input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="migrate">
	<span>Bekleyen migration: <strong>{implode(', ', $rvPending)|escape}</strong>. Satıcı paneli çalışmadan önce çalıştırın (yedek alın).</span>
	<button type="submit" class="btn btn-sm btn-dark ms-auto">Çalıştır</button>
</form>
{/if}

<ul class="nav nav-tabs mb-3">
	{foreach ['vendors' => 'Satıcılar', 'products' => 'Ürün Onayı', 'orders' => 'Satıcı Siparişleri', 'finance' => 'Finans & Ödemeler', 'settings' => 'Ayarlar'] as $k => $l}
	<li class="nav-item"><a class="nav-link{if $rvTab == $k} active{/if}" href="?tab={$k}">{$l}</a></li>
	{/foreach}
</ul>

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

{if $rvTab == 'settings'}
<div class="admin-panel p-3" style="max-width:640px"><form method="post"><input type="hidden" name="token" value="{$adminToken}"><input type="hidden" name="rvAction" value="settings">
	<label class="form-label">Varsayılan komisyon oranı (%)</label><input type="text" name="default_commission" class="form-control mb-3" value="{$rvDefaultCommission|escape}">
	<label class="form-label">Teslimattan sonra ödemeye hazır olma süresi (gün)</label><input type="text" name="hold_days" class="form-control mb-3" value="{$rvHoldDays|escape}">
	<div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="m" name="moderation" value="1"{if $rvModeration} checked{/if}><label class="form-check-label" for="m">Yeni satıcı ürünleri yönetici onayı beklesin (önerilir)</label></div>
	<div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="a" name="auto_approve" value="1"{if $rvAutoApprove} checked{/if}><label class="form-check-label" for="a">Satıcı başvurularını otomatik onayla (önerilmez)</label></div>
	<button class="btn btn-dark" type="submit">Kaydet</button></form></div>
{/if}
