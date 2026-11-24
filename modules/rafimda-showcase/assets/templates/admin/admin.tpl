{if $rsFlash}<div class="alert alert-{$rsFlash.type|escape}">{$rsFlash.message|escape}</div>{/if}
<div class="alert alert-info small">Ödeme / reklam faturalaması yoktur. Yerleşim, yönetici onayıyla ve tarih aralığıyla belirlenir. Ana sayfada görünmesi için temada <code>{ldelim}$hooks.home_showcase{rdelim}</code> (veya yedek olarak <code>home_bottom</code>) bağlı olmalıdır.</div>
<div class="admin-panel p-3 mb-3"><h5>Vitrin alanları</h5>
{foreach $rsSlots as $s}
<form method="post" class="row g-2 align-items-center mb-2 border-bottom pb-2"><input type="hidden" name="token" value="{$rsToken|escape}"><input type="hidden" name="rsAction" value="slot_save"><input type="hidden" name="code" value="{$s.code|escape}">
	<div class="col-auto" style="min-width:130px"><code>{$s.code|escape}</code> <small class="text-muted">{if $s.kind == 'banner'}banner{else}ürün{/if}</small></div>
	<div class="col-auto"><input class="form-control form-control-sm" name="title" value="{$s.title|escape}" aria-label="Başlık"></div>
	<div class="col-auto"><input class="form-control form-control-sm" style="width:80px" name="max_items" value="{$s.max_items|escape}" aria-label="Azami öğe"></div>
	<div class="col-auto"><label class="small"><input type="checkbox" name="active" value="1"{if $s.active} checked{/if}> aktif</label></div>
	<div class="col-auto"><label class="small"><input type="checkbox" name="vendor_requestable" value="1"{if $s.vendor_requestable} checked{/if}> satıcı talep edebilir</label></div>
	<div class="col-auto"><button class="btn btn-sm btn-dark" type="submit">Kaydet</button></div></form>
{/foreach}</div>
<div class="admin-panel p-3 mb-3"><h5>Öğe ekle</h5>
<form method="post" class="row g-2"><input type="hidden" name="token" value="{$rsToken|escape}"><input type="hidden" name="rsAction" value="item_add">
	<div class="col-auto"><select name="slot_code" class="form-select form-select-sm">{foreach $rsSlots as $s}<option value="{$s.code|escape}">{$s.title|escape}</option>{/foreach}</select></div>
	<div class="col-auto"><input class="form-control form-control-sm" style="width:110px" name="id_product" placeholder="Ürün ID"></div>
	<div class="col-auto"><input class="form-control form-control-sm" name="title" placeholder="Banner başlığı"></div>
	<div class="col-auto"><input class="form-control form-control-sm" name="image_url" placeholder="Görsel (https://… veya /yol)"></div>
	<div class="col-auto"><input class="form-control form-control-sm" name="link_url" placeholder="Bağlantı"></div>
	<div class="col-auto"><input type="date" class="form-control form-control-sm" name="starts_at" title="Başlangıç"></div><div class="col-auto"><input type="date" class="form-control form-control-sm" name="ends_at" title="Bitiş"></div>
	<div class="col-auto"><input class="form-control form-control-sm" style="width:70px" name="position" placeholder="Sıra" value="0"></div>
	<div class="col-auto"><button class="btn btn-sm btn-outline-dark" type="submit">Ekle</button></div></form></div>
<div class="admin-panel p-3"><h5>Öğeler / talepler</h5>
<ul class="nav nav-pills mb-2">{foreach ['pending' => 'Bekleyen', 'approved' => 'Onaylı', 'rejected' => 'Reddedilen', 'removed' => 'Kaldırılan'] as $k => $l}<li class="nav-item"><a class="nav-link py-1{if $rsStatus == $k} active{/if}" href="?status={$k}">{$l}</a></li>{/foreach}</ul>
<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>Alan</th><th>İçerik</th><th>Satıcı</th><th>Tarih aralığı</th><th></th></tr></thead><tbody>
{foreach $rsItems as $i}<tr><td>{$i.id_item|escape}</td><td>{$i.slot_title|escape}</td><td>{if $i.id_product}{$i.product_name|escape} <small class="text-muted">#{$i.id_product|escape}</small>{else}{$i.title|escape}{/if}</td><td>{if $i.id_vendor}#{$i.id_vendor|escape}{else}Yönetici{/if}</td><td>{$i.starts_at|escape} – {$i.ends_at|escape}</td>
	<td><form method="post" class="d-flex gap-1"><input type="hidden" name="token" value="{$rsToken|escape}"><input type="hidden" name="rsAction" value="item_moderate"><input type="hidden" name="id_item" value="{$i.id_item|escape}"><input name="note" class="form-control form-control-sm" style="width:130px" placeholder="Not" value="{$i.note|escape}">
		<button class="btn btn-sm btn-success" name="to" value="approved" type="submit">Onayla</button><button class="btn btn-sm btn-warning" name="to" value="rejected" type="submit">Reddet</button><button class="btn btn-sm btn-outline-secondary" name="to" value="removed" type="submit">Kaldır</button></form></td></tr>{foreachelse}<tr><td colspan="6" class="text-muted">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div></div>
