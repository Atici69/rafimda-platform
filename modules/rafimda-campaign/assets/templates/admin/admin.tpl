<div class="container-fluid">
	<h2>{$pageTitle|escape}</h2>
	{if $rcFlash}<div class="alert alert-{$rcFlash.type|escape}">{$rcFlash.message|escape}</div>{/if}
	<div class="alert alert-info small">Bu modül <b>ödeme almaz</b>. Vitrin ücreti yalnızca borç kaydıdır; tahsilatı havale/EFT ile alıp elle "ödendi" işaretlersiniz. Komisyon kampanyası yalnızca <b>yeni siparişlere</b> uygulanır; geçmiş kayıtlar değişmez.
		{if !$rcVendorModule}<br><b>Uyarı:</b> rafimda-vendor modülü kapalı/kurulu değil; komisyon kampanyaları işlemez.{/if}
		{if !$rcShowcaseModule}<br><b>Uyarı:</b> rafimda-showcase modülü kapalı/kurulu değil; vitrin ücreti/muafiyeti işlemez.{/if}</div>
	<ul class="nav nav-tabs mb-3">
		{foreach ['campaigns' => 'Kampanyalar', 'enrollments' => 'Katılımlar', 'charges' => 'Vitrin ücretleri', 'simulator' => 'Hesaplama simülatörü'] as $k => $l}
		<li class="nav-item"><a class="nav-link{if $rcTab == $k} active{/if}" href="?tab={$k}">{$l}</a></li>
		{/foreach}
	</ul>

	{if $rcTab == 'campaigns'}
	<div class="card p-3 mb-3"><h5>Tanımlı kampanyalar</h5>
		<table class="table table-sm"><thead><tr><th>Kod / Başlık</th><th>Tür</th><th>Kural</th><th>Pencere</th><th>Uygunluk</th><th>Durum</th><th></th></tr></thead><tbody>
		{foreach $rcCampaigns as $c}
			<tr><td><code>{$c.code|escape}</code><br>{$c.title|escape}</td>
			<td>{$rcTypes[$c.type]|default:$c.type|escape}</td>
			<td>{if $c.type == 'commission_discount'}{if $c.calc_mode == 'percent_off'}%{$c.value|escape} indirim{else}Sabit %{$c.value|escape}{/if}{if $c.min_rate > 0}, alt sınır %{$c.min_rate|escape}{/if}{else}Vitrin ücreti muaf{/if}<br><small>Süre: {if $c.benefit_days > 0}{$c.benefit_days|escape} gün{else}kampanya bitişine kadar{/if}{if $c.max_orders !== null}, en çok {$c.max_orders|escape} sipariş{/if}</small></td>
			<td><small>{$c.starts_at|default:'—'|escape} → {$c.ends_at|default:'—'|escape}</small></td>
			<td><small>{if $c.new_vendor_days}Yeni satıcı (≤{$c.new_vendor_days|escape} gün){else}Tüm satıcılar{/if}{if $c.plan_codes}; paket: {$c.plan_codes|escape}{/if}{if $c.vendor_ids}; satıcı no: {$c.vendor_ids|escape}{/if}<br>{if $c.auto_enroll}Otomatik katılım{/if}{if $c.vendor_can_join} Satıcı başvurabilir{/if}</small></td>
			<td>{if $c.active}<span class="badge bg-success">Açık</span>{else}<span class="badge bg-secondary">Kapalı</span>{/if}</td>
			<td class="text-nowrap"><a class="btn btn-sm btn-outline-dark" href="?tab=campaigns&edit={$c.id_campaign|escape}">Düzenle</a>
				<form method="post" style="display:inline"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="campaign_toggle"><input type="hidden" name="id_campaign" value="{$c.id_campaign|escape}"><input type="hidden" name="to" value="{if $c.active}0{else}1{/if}"><button class="btn btn-sm btn-outline-secondary" type="submit">{if $c.active}Kapat{else}Aç{/if}</button></form></td></tr>
		{foreachelse}<tr><td colspan="7" class="text-center text-muted">Henüz kampanya tanımlanmadı. Aşağıdan oluşturun.</td></tr>{/foreach}
		</tbody></table></div>

	<form method="post" class="card p-3"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="campaign_save"><input type="hidden" name="id_campaign" value="{if $rcEdit}{$rcEdit.id_campaign|escape}{else}0{/if}">
		<h5>{if $rcEdit}Kampanyayı düzenle: <code>{$rcEdit.code|escape}</code>{else}Yeni kampanya{/if}</h5>
		<div class="row g-2">
			<div class="col-md-3"><label class="small">Kod (küçük harf)<input class="form-control form-control-sm" name="code" required maxlength="32" value="{$rcEdit.code|default:''|escape}" placeholder="ilk-ay-vitrin"></label></div>
			<div class="col-md-5"><label class="small">Başlık<input class="form-control form-control-sm" name="title" required maxlength="120" value="{$rcEdit.title|default:''|escape}" placeholder="İlk ay ücretsiz vitrin"></label></div>
			<div class="col-md-4"><label class="small">Tür<select class="form-select form-select-sm" name="type">{foreach $rcTypes as $k => $l}<option value="{$k}"{if $rcEdit.type|default:'' == $k} selected{/if}>{$l|escape}</option>{/foreach}</select></label></div>
			<div class="col-12"><label class="small">Açıklama (satıcıya gösterilir)<input class="form-control form-control-sm" name="description" maxlength="255" value="{$rcEdit.description|default:''|escape}"></label></div>
			<div class="col-md-3"><label class="small">Katılım başlangıcı<input class="form-control form-control-sm" type="date" name="starts_at" value="{if $rcEdit.starts_at|default:''}{$rcEdit.starts_at|truncate:10:''|escape}{/if}"></label></div>
			<div class="col-md-3"><label class="small">Katılım bitişi<input class="form-control form-control-sm" type="date" name="ends_at" value="{if $rcEdit.ends_at|default:''}{$rcEdit.ends_at|truncate:10:''|escape}{/if}"></label></div>
			<div class="col-md-3"><label class="small">Avantaj süresi (gün; 0 = kampanya bitişine kadar)<input class="form-control form-control-sm" type="number" min="0" max="730" name="benefit_days" value="{$rcEdit.benefit_days|default:30|escape}"></label></div>
			<div class="col-md-3"><label class="small">En çok sipariş (boş: sınırsız)<input class="form-control form-control-sm" type="number" min="1" name="max_orders" value="{$rcEdit.max_orders|default:''|escape}"></label></div>
			<div class="col-md-4"><label class="small">Hesaplama kuralı (komisyon)<select class="form-select form-select-sm" name="calc_mode">{foreach $rcModes as $k => $l}<option value="{$k}"{if $rcEdit.calc_mode|default:'fixed_rate' == $k} selected{/if}>{$l|escape}</option>{/foreach}</select></label></div>
			<div class="col-md-2"><label class="small">Değer (%)<input class="form-control form-control-sm" name="value" value="{$rcEdit.value|default:''|escape}" placeholder="5"></label></div>
			<div class="col-md-2"><label class="small">Alt sınır (%)<input class="form-control form-control-sm" name="min_rate" value="{$rcEdit.min_rate|default:'0'|escape}"></label></div>
			<div class="col-md-4"><label class="small">Yeni satıcı süresi (gün; boş: herkes)<input class="form-control form-control-sm" type="number" min="1" name="new_vendor_days" value="{$rcEdit.new_vendor_days|default:''|escape}"></label></div>
			<div class="col-md-6"><label class="small">Uygun paket kodları (virgülle; boş: tümü)<input class="form-control form-control-sm" name="plan_codes" value="{$rcEdit.plan_codes|default:''|escape}" placeholder="free,pro"></label></div>
			<div class="col-md-6"><label class="small">Belirli satıcı numaraları (virgülle; boş: tümü)<input class="form-control form-control-sm" name="vendor_ids" value="{$rcEdit.vendor_ids|default:''|escape}" placeholder="3,7,12"></label></div>
			<div class="col-12">
				<label class="me-3 small"><input type="checkbox" name="active" value="1"{if $rcEdit.active|default:0} checked{/if}> Kampanya açık</label>
				<label class="me-3 small"><input type="checkbox" name="auto_enroll" value="1"{if $rcEdit.auto_enroll|default:0} checked{/if}> Uygun satıcı otomatik katılsın (ilk kullanımda)</label>
				<label class="small"><input type="checkbox" name="vendor_can_join" value="1"{if $rcEdit.vendor_can_join|default:0} checked{/if}> Satıcı panelinden başvurabilsin</label>
			</div>
		</div>
		<p class="small text-muted mt-2">Komisyon kampanyasında "Değer" sabit oranda yeni komisyon oranıdır; "yüzde indirim" kuralında mevcut orandan düşülür. Birden çok kampanya etkinse <b>en düşük</b> oran uygulanır ve oran asla standart orandan yüksek olamaz (standart: satıcıya özel oran veya platform varsayılanı %{$rcDefaultCommission|escape}).</p>
		<div><button class="btn btn-primary btn-sm" type="submit">Kaydet</button>{if $rcEdit} <a class="btn btn-sm btn-outline-secondary" href="?tab=campaigns">Vazgeç</a>{/if}</div>
	</form>
	{/if}

	{if $rcTab == 'enrollments'}
	<div class="card p-3 mb-3"><h5>Satıcıyı kampanyaya elle ekle</h5>
		<form method="post" class="row g-2"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="enroll">
			<div class="col-auto"><select name="id_campaign" class="form-select form-select-sm" required>{foreach $rcAllCampaigns as $c}<option value="{$c.id_campaign|escape}">{$c.title|escape}{if !$c.active} (kapalı){/if}</option>{/foreach}</select></div>
			<div class="col-auto"><input class="form-control form-control-sm" type="number" min="1" name="id_vendor" placeholder="Satıcı no" required></div>
			<div class="col-auto"><button class="btn btn-sm btn-dark" type="submit">Katılımı başlat</button></div>
		</form><small class="text-muted">Yönetici katılımı uygunluk kurallarını ve katılım penceresini atlar; her satıcı bir kampanyaya yalnızca bir kez katılabilir.</small></div>
	<div class="card p-3"><h5>Katılımlar</h5>
		<table class="table table-sm"><thead><tr><th>Satıcı</th><th>Kampanya</th><th>Başlangıç</th><th>Bitiş</th><th>Sipariş</th><th>Kaynak</th><th>Durum</th><th></th></tr></thead><tbody>
		{foreach $rcEnrollments as $e}<tr><td>{$e.store_name|default:'#'|escape} <small>(#{$e.id_vendor|escape})</small></td><td>{$e.title|escape}</td><td>{$e.started_at|escape}</td><td>{$e.expires_at|default:'—'|escape}</td><td>{$e.orders_used|escape}{if $e.max_orders !== null} / {$e.max_orders|escape}{/if}</td><td>{$e.source|escape}</td>
			<td>{if $e.live}<span class="badge bg-success">Etkin</span>{elseif $e.status == 'cancelled'}<span class="badge bg-secondary">İptal</span>{else}<span class="badge bg-light text-dark">Sona erdi</span>{/if}</td>
			<td>{if $e.live}<form method="post"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="enroll_end"><input type="hidden" name="id_enrollment" value="{$e.id_enrollment|escape}"><button class="btn btn-sm btn-outline-danger" type="submit">Sonlandır</button></form>{/if}</td></tr>
		{foreachelse}<tr><td colspan="8" class="text-center text-muted">Henüz katılım yok.</td></tr>{/foreach}
		</tbody></table></div>
	{/if}

	{if $rcTab == 'charges'}
	<div class="card p-3 mb-3"><h5>Vitrin ücreti</h5>
		<form method="post" class="row g-2 align-items-end"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="fee_save">
			<div class="col-auto"><label class="small">Talep başına vitrin ücreti (₺)<input class="form-control form-control-sm" name="fee" value="{$rcFee|escape}"></label></div>
			<div class="col-auto"><button class="btn btn-sm btn-dark" type="submit">Kaydet</button></div></form>
		<small class="text-muted">0 ise vitrin herkese ücretsizdir ve hiçbir borç kaydı oluşmaz. Ücret &gt; 0 ise yalnızca "ilk ay ücretsiz vitrin" katılımı olan satıcılar muaf tutulur; diğerlerinin talebi için borç kaydı açılır ve <b>tahsilat işaretlenmeden onaylanamaz</b>.</small></div>
	<div class="card p-3"><h5>Ücret kayıtları</h5>
		<p>{foreach ['due' => 'Bekleyen', 'settled' => 'Ödendi', 'waived' => 'Kampanya muafiyeti', 'void' => 'İptal', '' => 'Tümü'] as $k => $l}<a class="btn btn-sm {if $rcChargeStatus == $k}btn-dark{else}btn-outline-dark{/if}" href="?tab=charges&cs={$k}">{$l}</a> {/foreach}</p>
		<table class="table table-sm"><thead><tr><th>#</th><th>Satıcı</th><th>Vitrin kaydı</th><th>Tutar</th><th>Durum</th><th>Not</th><th></th></tr></thead><tbody>
		{foreach $rcCharges as $c}<tr><td>{$c.id_charge|escape}</td><td>{$c.store_name|default:'#'|escape}</td><td>#{$c.id_item|escape}</td><td>{$c.amount|escape} ₺</td><td>{$c.status|escape}</td><td>{$c.note|escape}</td>
			<td>{if $c.status == 'due'}<form method="post" class="d-flex gap-1"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="charge_settle"><input type="hidden" name="id_charge" value="{$c.id_charge|escape}"><input class="form-control form-control-sm" name="note" placeholder="Dekont/not"><button class="btn btn-sm btn-success" type="submit">Ödendi işaretle</button></form>{/if}</td></tr>
		{foreachelse}<tr><td colspan="7" class="text-center text-muted">Kayıt yok.</td></tr>{/foreach}
		</tbody></table></div>
	{/if}

	{if $rcTab == 'simulator'}
	<div class="card p-3"><h5>Komisyon hesaplama simülatörü</h5>
		<form method="get" class="row g-2 align-items-end"><input type="hidden" name="tab" value="simulator">
			<div class="col-auto"><label class="small">Satıcı no<input class="form-control form-control-sm" type="number" min="1" name="id_vendor" value="{$rcSimVendor|escape}" required></label></div>
			<div class="col-auto"><label class="small">Sipariş tutarı (₺)<input class="form-control form-control-sm" name="amount" value="{$rcSimAmount|escape}"></label></div>
			<div class="col-auto"><button class="btn btn-sm btn-dark" type="submit">Hesapla</button></div></form>
		{if $rcSim}{if !$rcSim.ok}<div class="alert alert-warning mt-2">{$rcSim.message|escape}</div>{else}
		<table class="table table-sm mt-3"><thead><tr><th>Senaryo</th><th>Oran</th><th>Komisyon</th><th>Satıcı kazancı</th></tr></thead><tbody>
		{foreach $rcSim.rows as $r}<tr><td>{$r.label|escape}</td><td>%{$r.rate|escape}</td><td>{$r.commission|escape} ₺</td><td>{$r.earning|escape} ₺</td></tr>{/foreach}</tbody></table>
		<small class="text-muted">Simülasyon hiçbir kayıt yazmaz ve otomatik katılım başlatmaz; yalnızca şu an etkin katılımları gösterir.</small>{/if}{/if}
	</div>
	{/if}
</div>
