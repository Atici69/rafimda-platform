{if $mhFlash}<div class="alert {$mhFlash.type|escape}">{$mhFlash.message|escape}</div>{/if}
<div class="alert warning"><strong>Önemli:</strong> Trendyol, Hepsiburada ve N11 için bağlantı testi ve stok/fiyat güncelleme hazırdır; pazaryerinde <b>yeni ürün oluşturma</b> henüz etkin değildir. Kuyruğa aldığınız yeni ürünler bu özellik açılana kadar "engellendi" olarak görünür. Amazon, Pazarama ve Ozon için entegrasyon henüz yoktur.</div>

{if $view == 'list'}
<div class="xh-card"><h2>Pazaryeri bağlantılarım</h2>
	{foreach $accounts as $a}
	<div class="row" style="border-bottom:1px solid #e5e7eb;padding:8px 0">
		<a href="{$mhBase|escape}?view=account&amp;id={$a.id_account|escape}" style="flex:1"><b>{$a.label|escape}</b> <span class="pill">{$a.marketplace|escape}</span></a>
		<span class="pill">{if $a.has_credentials}kimlik kayıtlı{else}kimlik yok{/if}</span>
		{if isset($marketplaces[$a.marketplace]) && !$marketplaces[$a.marketplace].implemented}<span class="pill">{if !empty($marketplaces[$a.marketplace].stock_price)}Yalnızca stok/fiyat{else}API uygulanmadı{/if}</span>{/if}
	</div>
	{foreachelse}<p class="muted">Henüz pazaryeri hesabı yok.</p>{/foreach}
	<p><a href="{$mhBase|escape}?view=listings">Gönderimler</a> · <a href="{$mhBase|escape}?view=errors">Hata geçmişi</a></p>
</div>
{if $mhCanManage}
<div class="xh-card"><h3>Yeni pazaryeri hesabı</h3>
	{foreach $marketplaces as $m}
	<details><summary><b>{$m.title|escape}</b>{if !$m.implemented} <span class="pill">{if !empty($m.stock_price)}Yalnızca stok/fiyat{else}API uygulanmadı{/if}</span>{/if}</summary>
	<form method="post" autocomplete="off"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="account_create"><input type="hidden" name="marketplace" value="{$m.key|escape}">
		<label>Hesap adı</label><input type="text" name="label" maxlength="120" required>
		{foreach $m.fields as $fk => $f}<label>{$f.label|escape}{if !$f.required} (isteğe bağlı){/if}</label><input type="{if $f.secret}password{else}text{/if}" name="cred[{$fk|escape}]" maxlength="{$f.max|escape}" autocomplete="new-password">{/foreach}
		<p><button class="btn" type="submit">Hesabı ekle</button></p>
	</form></details>
	{/foreach}
</div>{/if}
{/if}

{if $view == 'account'}
<p><a href="{$mhBase|escape}">← Hesaplar</a></p>
<h2>{$acc.label|escape} <span class="pill">{$acc.marketplace|escape}</span></h2>
{if !$adapter.implemented}<div class="alert info">{if !empty($adapter.stock_price)}{$adapter.title|escape}: bağlantı testi ve stok/fiyat güncelleme çekirdek istemci üzerinden hazırdır (canlı hesapla doğrulanmadı). Yeni ürün oluşturma henüz yapılmadı.{else}{$adapter.title|escape} için gerçek API entegrasyonu henüz yapılmadı: bağlantı test edilemez, ürün gönderilemez.{/if}</div>{/if}
<nav class="xh-tabs">{foreach ['general' => 'Genel', 'categories' => 'Kategori eşleme', 'products' => 'Ürünler', 'listings' => 'Gönderimler', 'errors' => 'Hatalar'] as $k => $t}<a class="{if $tab == $k}on{/if}" href="{$mhBase|escape}?view=account&amp;id={$acc.id_account|escape}&amp;tab={$k|escape}">{$t|escape}</a>{/foreach}</nav>

{if $tab == 'general'}
<div class="xh-card"><h3>Kimlik bilgileri</h3>
	<p class="muted">Değerler şifreli saklanır ve bir daha gösterilmez. Gizli alanı boş bırakırsanız mevcut değer korunur.</p>
	{if $mhCanManage}<form method="post" autocomplete="off"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="account_cred_save"><input type="hidden" name="id_account" value="{$acc.id_account|escape}">
	{foreach $adapter.fields as $fk => $f}<label>{$f.label|escape} <span class="pill">{if isset($credStatus[$fk]) && $credStatus[$fk]}kayıtlı{else}boş{/if}</span></label><input type="{if $f.secret}password{else}text{/if}" name="cred[{$fk|escape}]" maxlength="{$f.max|escape}" autocomplete="new-password">{/foreach}
	<p><button class="btn" type="submit">Kaydet</button></p></form>
	<form method="post" class="row"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="account_test"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn navy sm" type="submit">Bağlantıyı test et</button></form>
	{if $acc.last_test_at}<p class="muted">Son test: {$acc.last_test_at|escape} — {$acc.last_error|escape}</p>{/if}
	<form method="post" onsubmit="return confirm('Hesap ve pazaryeri kayıtları silinsin mi? Ürünler silinmez.')"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="account_delete"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn danger sm" type="submit">Hesabı sil</button></form>{/if}
	<p>Gönderim durumu: {foreach $counts as $st => $n}<span class="pill">{$st|escape}: {$n|escape}</span> {foreachelse}<span class="muted">kayıt yok</span>{/foreach}</p>
</div>
{/if}

{if $tab == 'categories'}
<div class="xh-card"><h3>Kategori eşleme</h3><p class="muted">Ürünlerinizin bulunduğu her kategoriyi pazaryerindeki kategori numarasına bağlayın (numarayı pazaryeri satıcı panelindeki kategori ağacında görürsünüz). Eşlenmemiş kategorideki ürünler gönderilemez.</p>
	{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="category_map_save"><input type="hidden" name="id_account" value="{$acc.id_account|escape}">
	{foreach $categories as $c}<div class="row" style="border-bottom:1px solid #eef1f5;padding:8px 0"><span style="flex:1 1 280px">{$c.category_name|escape}{if $c.products|default:0} <span class="pill">{$c.products|escape} ürün</span>{/if}</span><input type="text" style="flex:0 0 150px;width:150px" name="remote[{$c.id_category|escape}]" value="{if isset($catMap[$c.id_category])}{$catMap[$c.id_category].remote_category_id|escape}{/if}" placeholder="kategori no" maxlength="60" aria-label="Pazaryeri kategori numarası"><input type="text" style="flex:1 1 200px;width:auto;max-width:300px" name="remote_name[{$c.id_category|escape}]" value="{if isset($catMap[$c.id_category])}{$catMap[$c.id_category].remote_category_name|escape}{/if}" placeholder="kategori adı (isteğe bağlı)" maxlength="190" aria-label="Pazaryeri kategori adı"></div>{/foreach}
	<p><button class="btn" type="submit">Eşlemeleri kaydet</button></p></form>{/if}
</div>
{/if}

{if $tab == 'products'}
<div class="xh-card"><h3>Ürünler</h3>
	{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="queue_products"><input type="hidden" name="id_account" value="{$acc.id_account|escape}">
	{foreach $products as $p}<label class="chk"><input type="checkbox" name="ids[]" value="{$p.id_product|escape}"> {$p.product_name|escape} <span class="muted">{$p.stock_code|escape}</span>{if !isset($catMap[$p.id_category])} <span class="pill">kategori eşlenmemiş</span>{/if}</label>{foreachelse}<p class="muted">Gönderilecek ürün yok.</p>{/foreach}
	<p><button class="btn" type="submit">Seçilenleri gönderim kuyruğuna al</button>{if !empty($adapter.stock_price)} <button class="btn ghost" type="submit" name="mhAction" value="push_stock_price">Seçilenlerin stok/fiyatını gönder</button>{/if}</p></form>{/if}
</div>
{/if}

{if $tab == 'listings'}
<div class="xh-card"><h3>Gönderimler</h3>
	{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="retry_failed"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn ghost sm" type="submit">Başarısızları yeniden dene</button></form>{/if}
	{include file="file:{$mhTplDir}mh_listings.tpl"}
</div>
{/if}

{if $tab == 'errors'}<div class="xh-card"><h3>Hatalar</h3>{include file="file:{$mhTplDir}mh_errors.tpl"}</div>{/if}
{/if}

{if $view == 'listings'}<div class="xh-card"><h2>Gönderimler</h2><p><a href="{$mhBase|escape}">← Hesaplar</a></p>{include file="file:{$mhTplDir}mh_listings.tpl"}</div>{/if}
{if $view == 'errors'}<div class="xh-card"><h2>Hata geçmişi</h2><p><a href="{$mhBase|escape}">← Hesaplar</a></p>{include file="file:{$mhTplDir}mh_errors.tpl"}</div>{/if}
