{if $rcFlash}<div class="alert alert-{$rcFlash.type|escape}">{$rcFlash.message|escape}</div>{/if}

<ul class="nav nav-tabs mb-3">
	{foreach ['tree' => 'Kategori Ağacı', 'map' => 'Eşleştirmeler', 'test' => 'Eşleştirmeyi Dene'] as $k => $l}
	<li class="nav-item"><a class="nav-link{if $rcTab == $k} active{/if}" href="?tab={$k|escape}">{$l|escape}</a></li>
	{/foreach}
</ul>

{if $rcTab == 'tree'}
<div class="admin-panel p-3 mb-3">
	<h5>Pazaryeri kategori ağacı</h5>
	<p class="small text-muted">Kadın, Erkek, Anne &amp; Çocuk, Ev &amp; Yaşam, Mobilya, Süpermarket, Kozmetik, Ayakkabı &amp; Çanta, Saat &amp; Aksesuar, Elektronik, Spor &amp; Outdoor, Kitap/Kırtasiye/Hobi, Oto/Bahçe/Yapı Market, Pet Shop ve Sağlık ana kategorileri ile alt kategorileri. Yalnızca <strong>eksik olanlar eklenir</strong>; mevcut kategorileriniz silinmez, taşınmaz, yeniden adlandırılmaz. Tekrar çalıştırmak güvenlidir.</p>
	<div class="row g-3 mb-3">
		<div class="col-auto"><div class="border rounded p-2 text-center"><div class="fs-4 fw-bold">{$rcPlan.total|escape}</div><small class="text-muted">ağaçtaki kategori</small></div></div>
		<div class="col-auto"><div class="border rounded p-2 text-center"><div class="fs-4 fw-bold">{$rcPlan.existing|escape}</div><small class="text-muted">zaten var</small></div></div>
		<div class="col-auto"><div class="border rounded p-2 text-center"><div class="fs-4 fw-bold text-primary">{$rcPlan.create|@count}</div><small class="text-muted">eklenecek</small></div></div>
	</div>
	{if $rcPlan.create}
	<details class="mb-3"><summary class="small">Eklenecek kategorileri göster</summary>
		<ul class="small mt-2" style="columns:2;max-height:360px;overflow:auto">{foreach $rcPlan.create as $p}<li>{$p|escape}</li>{/foreach}</ul>
	</details>
	<form method="post" class="d-flex flex-wrap gap-2 align-items-center"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="tree_apply">
		<label class="small">Onay için <strong>EKLE</strong> yazın: <input name="confirm" class="form-control form-control-sm d-inline-block" style="width:110px" autocomplete="off"></label>
		<button class="btn btn-sm btn-primary" type="submit">Eksik kategorileri ekle</button>
		<small class="text-muted">Önerilir: önce veritabanı yedeği alın.</small>
	</form>
	{else}
	<div class="alert alert-success small mb-0">Ağacın tamamı sitenizde mevcut.</div>
	{/if}
</div>
{/if}

{if $rcTab != 'tree'}
<datalist id="rcCatList">{foreach $rcCats as $c}<option value="{$c.path|escape} #{$c.id|escape}"></option>{/foreach}</datalist>
{/if}

{if $rcTab == 'map'}
<div class="admin-panel p-3 mb-3">
	<p class="small text-muted">XML, Excel ve pazaryerinden gelen kategori adları burada toplanır. Puanı <strong>{$rcThreshold|escape}</strong> ve üzeri olanlar otomatik eşlenir; altındakiler <em>bekleyen</em> olarak önerilir ve onaylayana kadar ürün varsayılan kategoriye gider (yanlış kategoriye ürün atılmaz). Kaydettiğiniz eşleme kalıcıdır ve tüm satıcılar için geçerlidir.</p>
	<div class="btn-group btn-group-sm mb-3">
		{foreach ['pending' => 'Bekleyen öneriler', 'auto' => 'Otomatik', 'manual' => 'Elle', 'ignore' => 'Yok sayılan', 'all' => 'Tümü'] as $k => $l}
		<a class="btn {if $rcOrigin == $k}btn-dark{else}btn-outline-dark{/if}" href="?tab=map&origin={$k|escape}">{$l|escape}</a>
		{/foreach}
	</div>
	{if $rcOrigin == 'pending' && $rcMaps}
	<form method="post" id="rcBulk"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="accept_suggestions"></form>
	{/if}
	<div class="table-responsive"><table class="table table-sm align-middle">
		<thead><tr>{if $rcOrigin == 'pending'}<th><input type="checkbox" onclick="document.querySelectorAll('.rc-k').forEach(function(x){ x.checked = this.checked; }, this)" aria-label="Tümünü seç"></th>{/if}<th>Kaynak kategori</th><th>Kategori</th><th>Puan</th><th>Kullanım</th><th>Durum</th><th style="min-width:340px">Düzelt</th></tr></thead>
		<tbody>
		{foreach $rcMaps as $m}
		<tr>
			{if $rcOrigin == 'pending'}<td>{if $m.suggest_id > 0}<input type="checkbox" class="rc-k" name="keys[]" value="{$m.source_key|escape}" form="rcBulk">{/if}</td>{/if}
			<td><strong>{$m.source_text|escape}</strong></td>
			<td>{if $m.id_category > 0}{$m.category_name|default:'(silinmiş)'|escape}{elseif $m.suggest_id > 0}<span class="text-muted">Öneri:</span> {$m.suggest_name|escape}{else}<span class="text-muted">—</span>{/if}</td>
			<td>{$m.score|string_format:"%.2f"}</td>
			<td>{$m.hits|escape}</td>
			<td>{if $m.origin == 'manual'}<span class="badge text-bg-success">elle</span>{elseif $m.origin == 'auto'}<span class="badge text-bg-primary">otomatik</span>{elseif $m.origin == 'ignore'}<span class="badge text-bg-secondary">yok sayılıyor</span>{else}<span class="badge text-bg-warning">bekliyor</span>{/if}</td>
			<td>
				<form method="post" class="d-flex gap-1"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="map_save"><input type="hidden" name="source" value="{$m.source_text|escape}">
					<input class="form-control form-control-sm" name="category" list="rcCatList" placeholder="Kategori ara…" aria-label="Kategori">
					<button class="btn btn-sm btn-dark" type="submit">Kaydet</button>
					<button class="btn btn-sm btn-outline-secondary" type="submit" name="rcAction" value="map_ignore" title="Eşleştirme; varsayılan kategori kullanılsın">Yok say</button>
				</form>
			</td>
		</tr>
		{foreachelse}
		<tr><td colspan="7" class="text-muted text-center py-4">Kayıt yok. XML/Excel içe aktardıkça kategori adları burada görünür.</td></tr>
		{/foreach}
		</tbody>
	</table></div>
	{if $rcOrigin == 'pending' && $rcMaps}<button class="btn btn-sm btn-primary" type="submit" form="rcBulk">Seçili önerileri onayla</button>{/if}
</div>
{/if}

{if $rcTab == 'test'}
<div class="admin-panel p-3 mb-3" style="max-width:860px">
	<form method="post" class="d-flex gap-2 mb-3"><input type="hidden" name="token" value="{$rcToken|escape}"><input type="hidden" name="rcAction" value="test">
		<input class="form-control" name="source" value="{$rcTest.source|default:''|escape}" placeholder="Örn. Elektronik > Cep Telefonu Aksesuarları > Kılıf  ya da  Kadın Tişört">
		<button class="btn btn-dark" type="submit">Dene</button>
	</form>
	{if $rcTest}
		{if $rcTest.match.id > 0}
		<div class="alert {if $rcTest.auto}alert-success{else}alert-warning{/if}">
			<strong>{$rcTest.match.path|escape}</strong> — puan {$rcTest.match.score|string_format:"%.2f"}
			{if $rcTest.auto}(otomatik eşlenir){else}(eşik altı: yalnızca öneri, onay gerekir){/if}
		</div>
		{if $rcTest.match.alternatives}<p class="small text-muted mb-1">Diğer adaylar:</p><ul class="small">{foreach $rcTest.match.alternatives as $a}<li>{$a.path|escape} — {$a.score|string_format:"%.2f"}</li>{/foreach}</ul>{/if}
		{else}
		<div class="alert alert-secondary">Uygun kategori bulunamadı. Önce "Kategori Ağacı" sekmesinden eksik kategorileri ekleyin.</div>
		{/if}
	{/if}
</div>
{/if}
