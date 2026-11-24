{if $riFlash}<div class="alert alert-{$riFlash.type|escape}">{$riFlash.message|escape}</div>{/if}
<div class="alert {if $riSettings.live}alert-danger{else}alert-info{/if}">
	{if $riSettings.live}<strong>CANLI fatura gönderimi AÇIK.</strong> Gerçek mali belgeler yalnızca uygulanmış entegratör ve canlı hesapla gönderilir.
	{else}<strong>Canlı gönderim kapalı.</strong> Yalnızca TEST bağdaştırıcısı çalışır; üretilen belgelerin mali değeri yoktur ve GİB'e gönderilmez.{/if}
	Vergisel kurallar (kesim zamanı, KDV, belge türü) mali müşavir onayı olmadan kesin kabul edilmemelidir.
</div>
<ul class="nav nav-tabs mb-3">
	{foreach ['invoices' => 'Faturalar', 'prepare' => 'Fatura hazırla', 'integrator' => 'Entegratör', 'settings' => 'Ayarlar', 'errors' => 'Hata kayıtları'] as $k => $l}
	<li class="nav-item"><a class="nav-link{if $riTab == $k} active{/if}" href="?tab={$k}">{$l}{if $k == 'invoices' && $riCounts.ready|default:0} <span class="badge text-bg-primary">{$riCounts.ready}</span>{/if}{if $k == 'errors' && $riCounts.error|default:0} <span class="badge text-bg-danger">{$riCounts.error}</span>{/if}</a></li>
	{/foreach}
</ul>

{if $riOne}
<p><a href="?tab=invoices">← Faturalar</a></p>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body">
	<h2 class="h5">{$riKinds[$riOne.kind]|escape} #{$riOne.id_invoice} <span class="badge text-bg-{if $riOne.status == 'accepted' || $riOne.status == 'sent'}success{elseif $riOne.status == 'ready'}primary{elseif $riOne.status == 'cancelled'}secondary{elseif $riOne.status == 'draft'}warning{else}danger{/if}">{$riStatuses[$riOne.status]|escape}</span> {if $riOne.environment == 'test'}<span class="badge text-bg-warning">TEST</span>{/if}</h2>
	<p class="small">Düzenleyen: <strong>{if $riOne.issuer_type == 'vendor'}Satıcı #{$riOne.id_vendor}{else}Rafımda{/if}</strong> · Alıcı: {$riOne.buyer_name|escape} ({$riOne.buyer_type|escape}{if $riOne.buyer_tax_no} · {$riOne.buyer_tax_no|escape}{/if}) · Sipariş: {if $riOne.id_order}#{$riOne.id_order}{else}—{/if}{if $riOne.period} · Dönem {$riOne.period|escape}{/if}</p>
	<p class="small">Belge türü: {$riDocTypes[$riOne.doc_type]|default:'gönderimde belirlenir'|escape} · No: {$riOne.invoice_no|default:'—'|escape} · ETTN: {$riOne.ettn|default:'—'|escape}</p>
	{if $riOne.last_error}<div class="alert alert-warning small">{$riOne.last_error|escape}</div>{/if}
	<table class="table table-sm"><thead><tr><th>Kalem</th><th class="text-end">Adet</th><th class="text-end">KDV %</th><th class="text-end">KDV</th><th class="text-end">Toplam</th></tr></thead><tbody>
	{foreach $riOneLines as $l}<tr><td>{$l.name|escape}{if $l.vat_source == 'setting'} <small class="text-muted">(KDV ayardan)</small>{/if}</td><td class="text-end">{$l.qty}</td><td class="text-end">{$l.vat_rate}</td><td class="text-end">{$l.vat|string_format:'%.2f'}</td><td class="text-end">{$l.gross|string_format:'%.2f'}</td></tr>{/foreach}
	<tr><th colspan="3">KDV hariç {$riOne.net_total|string_format:'%.2f'} · KDV {$riOne.vat_total|string_format:'%.2f'}</th><th colspan="2" class="text-end">{$riOne.gross_total|string_format:'%.2f'} TL</th></tr>
	</tbody></table>
	{if $riOne.pdf_file}<a class="btn btn-outline-dark btn-sm" href="?tab=invoices&pdf={$riOne.id_invoice}" target="_blank">PDF görüntüle</a>{/if}
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-body">
	<h3 class="h6">İşlemler</h3>
	{if in_array($riOne.status, ['ready','error','rejected']) && !$riOne.ettn}
	<form method="post" class="mb-2"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="send"><input type="hidden" name="id_invoice" value="{$riOne.id_invoice}"><button class="btn btn-primary btn-sm" type="submit">Entegratöre gönder</button></form>
	<form method="post" class="mb-2 d-flex gap-2"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="doctype"><input type="hidden" name="id_invoice" value="{$riOne.id_invoice}">
		<select class="form-select form-select-sm" name="doc_type"><option value="efatura">e-Fatura</option><option value="earsiv">e-Arşiv</option></select><button class="btn btn-outline-secondary btn-sm" type="submit">Türü elle seç</button></form>
	{/if}
	{if in_array($riOne.status, ['sent','accepted'])}
	<form method="post" class="mb-2"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="return"><input type="hidden" name="id_invoice" value="{$riOne.id_invoice}"><input class="form-control form-control-sm mb-1" name="note" placeholder="İade notu"><button class="btn btn-outline-warning btn-sm" type="submit">İade kaydı aç</button></form>
	{/if}
	{if $riOne.status != 'cancelled'}
	<form method="post"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="cancel"><input type="hidden" name="id_invoice" value="{$riOne.id_invoice}"><input class="form-control form-control-sm mb-1" name="reason" placeholder="İptal gerekçesi (zorunlu)"><button class="btn btn-outline-danger btn-sm" type="submit">İptal et (kayıt silinmez)</button></form>
	{/if}
	<h3 class="h6 mt-3">Geçmiş</h3>
	<ul class="small">{foreach $riOneEvents as $e}<li class="{if $e.is_error}text-danger{/if}">{$e.date_add|escape} · {$e.event|escape} · {$e.actor|escape} — {$e.detail|escape}</li>{/foreach}</ul>
</div></div></div>
</div>
{elseif $riTab == 'invoices'}
<form class="row g-2 mb-3" method="get"><input type="hidden" name="tab" value="invoices">
	<div class="col-auto"><select class="form-select" name="status"><option value="">Tüm durumlar</option>{foreach $riStatuses as $k => $l}<option value="{$k}">{$l|escape} ({$riCounts[$k]|default:0})</option>{/foreach}</select></div>
	<div class="col-auto"><select class="form-select" name="kind"><option value="">Tüm türler</option>{foreach $riKinds as $k => $l}<option value="{$k}">{$l|escape}</option>{/foreach}</select></div>
	<div class="col-auto"><input class="form-control" name="order" placeholder="Sipariş no"></div><div class="col-auto"><button class="btn btn-outline-dark">Süz</button></div></form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>#</th><th>Tür</th><th>Düzenleyen</th><th>Alıcı</th><th>Sipariş</th><th class="text-end">Tutar</th><th>Durum</th><th>No</th><th></th></tr></thead><tbody>
{foreach $riList as $i}<tr><td>{$i.id_invoice}</td><td>{$riKinds[$i.kind]|escape}</td><td>{if $i.issuer_type == 'vendor'}{$i.store_name|default:'Satıcı'|escape}{else}Rafımda{/if}</td><td>{$i.buyer_name|escape}</td><td>{if $i.id_order}#{$i.id_order}{elseif $i.period}{$i.period|escape}{/if}</td>
	<td class="text-end">{$i.gross_total|string_format:'%.2f'}</td><td>{$riStatuses[$i.status]|escape}{if $i.environment == 'test' && $i.ettn} <span class="badge text-bg-warning">TEST</span>{/if}</td><td>{$i.invoice_no|escape}</td><td><a class="btn btn-sm btn-outline-primary" href="?tab=invoices&id={$i.id_invoice}">Aç</a></td></tr>
{foreachelse}<tr><td colspan="9" class="text-muted">Fatura yok.</td></tr>{/foreach}
</tbody></table></div></div>
{elseif $riTab == 'prepare'}
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body">
	<h2 class="h6">Faturası hazırlanmamış siparişler</h2>
	<p class="small text-muted">Hazırlama, siparişi satıcılara göre böler: her satıcı kendi satış faturasını, Rafımda yalnızca kendi sattığı ürünlerin faturasını düzenler.</p>
	<table class="table table-sm"><thead><tr><th>Sipariş</th><th>Müşteri</th><th>Durum</th><th class="text-end">Tutar</th><th></th></tr></thead><tbody>
	{foreach $riOrders as $o}<tr><td>{$o.reference|escape} (#{$o.id_order})</td><td>{$o.customer_name|escape}</td><td>{$o.status}</td><td class="text-end">{$o.total|string_format:'%.2f'}</td>
		<td><form method="post"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="prepare"><input type="hidden" name="id_order" value="{$o.id_order}"><button class="btn btn-sm btn-outline-primary" type="submit">Hazırla</button></form></td></tr>
	{foreachelse}<tr><td colspan="5" class="text-muted">Bekleyen sipariş yok.</td></tr>{/foreach}
	</tbody></table>
</div></div></div>
<div class="col-lg-5">
	<div class="card mb-3"><div class="card-body"><h2 class="h6">Rafımda hizmet/komisyon faturaları</h2>
		<form method="post" class="d-flex gap-2"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="service"><input class="form-control" name="period" value="{$riPeriod|escape}" pattern="\d{literal}{4}{/literal}-\d{literal}{2}{/literal}"><button class="btn btn-outline-primary" type="submit">Dönemi hazırla</button></form>
		<p class="small text-muted mt-2">Teslim edilmiş satıcı siparişlerinin komisyonu, satıcı başına tek fatura. Hizmet KDV oranı Ayarlar'dan gelir.</p></div></div>
	<div class="card"><div class="card-body"><h2 class="h6">Manuel fatura (Rafımda)</h2>
		<form method="post"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="manual">
			<select class="form-select mb-2" name="buyer_type"><option value="individual">Bireysel</option><option value="company">Kurumsal</option></select>
			<input class="form-control mb-2" name="buyer_name" placeholder="Alıcı adı / unvanı" required>
			<div class="d-flex gap-2 mb-2"><input class="form-control" name="buyer_tax_no" placeholder="VKN / TCKN"><input class="form-control" name="buyer_tax_office" placeholder="Vergi dairesi"></div>
			<input class="form-control mb-2" name="buyer_address" placeholder="Adres" required><input class="form-control mb-2" name="buyer_email" placeholder="E-posta">
			<input class="form-control mb-2" name="description" placeholder="Açıklama (hizmet/ürün)" required>
			<div class="d-flex gap-2 mb-2"><input class="form-control" name="gross" placeholder="KDV dahil tutar" required><input class="form-control" name="vat_rate" placeholder="KDV %" required></div>
			<button class="btn btn-outline-primary" type="submit">Hazırla</button></form></div></div>
</div>
</div>
{elseif $riTab == 'integrator'}
<div class="card"><div class="card-body">
	<h2 class="h6">Rafımda (platform) entegratör hesabı</h2>
	<p class="small">Mevcut: {if $riAccount}<strong>{$riAccount.integrator|escape}</strong> · ortam {$riAccount.environment|escape} · durum {$riAccount.status|escape}{if $riAccount.has_secret} · şifre kayıtlı{/if}{if $riAccount.last_error} · <span class="text-danger">{$riAccount.last_error|escape}</span>{/if}{else}tanımlı değil{/if}</p>
	<form method="post" class="row g-2"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="account">
		<div class="col-md-4"><label class="form-label small" for="ri-int">Entegratör</label><select class="form-select" id="ri-int" name="integrator">{foreach $riAdapters as $k => $a}<option value="{$k}"{if $riAccount && $riAccount.integrator == $k} selected{/if}>{$a.label|escape}</option>{/foreach}</select></div>
		<div class="col-md-2"><label class="form-label small" for="ri-env">Ortam</label><select class="form-select" id="ri-env" name="environment"><option value="test">Test</option><option value="live">Canlı</option></select></div>
		<div class="col-md-3"><label class="form-label small" for="ri-user">Kullanıcı adı</label><input class="form-control" id="ri-user" name="username" value="{$riAccount.username|default:''|escape}" autocomplete="off"></div>
		<div class="col-md-3"><label class="form-label small" for="ri-sec">Şifre / API anahtarı</label><input class="form-control" id="ri-sec" type="password" name="secret" autocomplete="new-password" placeholder="{if $riAccount && $riAccount.has_secret}değiştirmek için yazın{/if}"></div>
		<div class="col-12"><button class="btn btn-primary" type="submit">Kaydet</button></div>
	</form>
	<form method="post" class="mt-2"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="test"><button class="btn btn-outline-secondary btn-sm" type="submit">Bağlantıyı test et</button></form>
	<hr><h3 class="h6">Bağdaştırıcılar</h3>
	<ul class="small">{foreach $riAdapters as $k => $a}<li><strong>{$k|escape}</strong>: {$a.label|escape} — {if $a.ready}kullanılabilir{else}<span class="text-danger">hazır değil</span>{/if}</li>{/foreach}</ul>
	<p class="small text-muted">Yeni entegratör eklemek: <code>lib/adapters/</code> altında <code>RiAdapter</code> arayüzünü uygulayın. Şifreler libsodium ile şifrelenir; arayüzde ve kayıtlarda gösterilmez.</p>
</div></div>
{elseif $riTab == 'settings'}
<div class="card"><div class="card-body">
	<form method="post"><input type="hidden" name="token" value="{$riToken|escape}"><input type="hidden" name="riAction" value="settings">
		<label class="form-label" for="ri-tr">Faturanın hazırlanacağı an</label>
		<select class="form-select mb-1" id="ri-tr" name="trigger">{foreach $riTriggers as $k => $l}<option value="{$k}"{if $riSettings.trigger == $k} selected{/if}>{$l|escape}</option>{/foreach}</select>
		<p class="small text-muted">Sipariş oluşması tek başına fatura kesim zamanı değildir; doğru tetikleyiciyi mali müşavirinizle belirleyin.</p>
		<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="auto_send" value="1" id="ri-as"{if $riSettings.auto_send} checked{/if}><label class="form-check-label" for="ri-as">Hazırlanan faturayı otomatik gönder (canlı gönderim kapalıyken yalnızca test)</label></div>
		<div class="row g-2 mb-3">
			<div class="col-md-4"><label class="form-label" for="ri-dv">Varsayılan KDV % (ürün KDV'si yoksa)</label><input class="form-control" id="ri-dv" name="default_vat" value="{$riSettings.default_vat|escape}" placeholder="boş = tanımsız"></div>
			<div class="col-md-4"><label class="form-label" for="ri-sv">Hizmet/komisyon KDV %</label><input class="form-control" id="ri-sv" name="service_vat" value="{$riSettings.service_vat|escape}" placeholder="boş = tanımsız"></div>
		</div>
		<div class="border border-danger rounded p-2 mb-3">
			<div class="form-check"><input class="form-check-input" type="checkbox" name="live" value="1" id="ri-live"{if $riSettings.live} checked{/if}><label class="form-check-label" for="ri-live"><strong>Canlı fatura gönderimi</strong></label></div>
			<input class="form-control mt-1" name="live_confirm" autocomplete="off" placeholder="Açmak için yazın: CANLI FATURA GONDERIMINI ACIYORUM">
			<p class="small text-danger mb-0">Yalnızca işletme sahibinin yazılı onayı ve mali müşavir doğrulamasından sonra.</p>
		</div>
		<button class="btn btn-primary" type="submit">Kaydet</button>
	</form>
</div></div>
{elseif $riTab == 'errors'}
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tarih</th><th>Fatura</th><th>Olay</th><th>Ayrıntı</th></tr></thead><tbody>
{foreach $riErrors as $e}<tr><td>{$e.date_add|escape}</td><td>{if $e.id_invoice}<a href="?tab=invoices&id={$e.id_invoice}">#{$e.id_invoice}</a>{else}—{/if}</td><td>{$e.event|escape}</td><td>{$e.detail|escape}</td></tr>{foreachelse}<tr><td colspan="4" class="text-muted">Hata kaydı yok.</td></tr>{/foreach}
</tbody></table></div></div>
{/if}
