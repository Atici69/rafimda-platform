{if $flash}
<div class="alert alert-{$flashType|escape}">{$flash|escape}</div>
{/if}

<div class="admin-panel p-3 mb-4">
	<div class="d-flex flex-wrap gap-3 align-items-center">
		<div>
			<h2 class="h6 mb-1">Satıcı özeti</h2>
			<span class="text-muted">Satıcı paneli girişi: <a href="{$rvPanelUrl|escape}" target="_blank" rel="noopener">{$rvPanelUrl|escape}</a></span>
		</div>
		<div class="ms-auto d-flex gap-2 flex-wrap">
			{foreach $rvStatuses as $key => $label}
			<a class="badge text-bg-{if $key == 'pending'}warning{elseif $key == 'active'}success{elseif $key == 'suspended'}secondary{else}danger{/if} text-decoration-none p-2"
				href="?status={$key|escape}">{$label|escape}: {$rvCounts[$key]|default:0}</a>
			{/foreach}
			<a class="badge text-bg-light text-decoration-none p-2" href="?">Tümü</a>
		</div>
	</div>

	{if $rvPending}
	<form method="post" class="alert alert-warning mt-3 mb-0">
		<input type="hidden" name="token" value="{$adminToken}">
		Bekleyen migration: <strong>{implode(', ', $rvPending)|escape}</strong>
		<button type="submit" name="rvRunMigrations" value="1" class="btn btn-sm btn-dark ms-2">Çalıştır</button>
	</form>
	{/if}

	<form method="post" class="mt-3 d-flex align-items-center gap-3">
		<input type="hidden" name="token" value="{$adminToken}">
		<div class="form-check">
			<input type="checkbox" class="form-check-input" id="rvAuto" name="auto_approve" value="1"{if $rvAutoApprove} checked{/if}>
			<label class="form-check-label" for="rvAuto">Yeni satıcı başvurularını otomatik onayla (önerilmez)</label>
		</div>
		<button type="submit" name="rvSaveSettings" value="1" class="btn btn-sm btn-outline-dark">Kaydet</button>
	</form>
</div>

<div class="admin-panel p-3">
	<form method="get" class="row g-2 mb-3">
		<div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Mağaza, e-posta ara" value="{$rvQuery|escape}"></div>
		<div class="col-md-3">
			<select name="status" class="form-select">
				<option value="">Tüm durumlar</option>
				{foreach $rvStatuses as $key => $label}<option value="{$key|escape}"{if $rvFilterStatus == $key} selected{/if}>{$label|escape}</option>{/foreach}
			</select>
		</div>
		<div class="col-md-2"><button class="btn btn-dark w-100" type="submit">Filtrele</button></div>
	</form>

	<div class="table-responsive">
		<table class="table align-middle">
			<thead><tr><th>#</th><th>Mağaza</th><th>Sahip</th><th>Ürün</th><th>Durum</th><th>Başvuru</th><th style="min-width:260px">İşlem</th></tr></thead>
			<tbody>
			{foreach $rvVendors as $v}
				<tr>
					<td>{$v.id_vendor|escape}</td>
					<td><strong>{$v.store_name|escape}</strong><br><small class="text-muted">/{$v.store_slug|escape} · {$v.city|escape}</small></td>
					<td>{$v.owner_name|escape}<br><small class="text-muted">{$v.owner_email|escape}</small></td>
					<td>{$v.product_count|escape}</td>
					<td>{$rvStatuses[$v.status]|default:$v.status|escape}{if $v.rejection_reason}<br><small class="text-muted">{$v.rejection_reason|escape}</small>{/if}</td>
					<td><small>{$v.date_add|escape}</small></td>
					<td>
						<form method="post" class="d-flex gap-1 flex-wrap align-items-center">
							<input type="hidden" name="token" value="{$adminToken}">
							<input type="hidden" name="rvVendorAction" value="1">
							<input type="hidden" name="id_vendor" value="{$v.id_vendor|escape}">
							<input type="text" name="reason" class="form-control form-control-sm" style="max-width:130px" placeholder="Red nedeni">
							{if $v.status != 'active'}<button name="to_status" value="active" class="btn btn-sm btn-success">Onayla</button>{/if}
							{if $v.status == 'active'}<button name="to_status" value="suspended" class="btn btn-sm btn-secondary">Askıya al</button>{/if}
							{if $v.status != 'rejected'}<button name="to_status" value="rejected" class="btn btn-sm btn-outline-danger">Reddet</button>{/if}
						</form>
					</td>
				</tr>
			{foreachelse}
				<tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr>
			{/foreach}
			</tbody>
		</table>
	</div>
</div>
