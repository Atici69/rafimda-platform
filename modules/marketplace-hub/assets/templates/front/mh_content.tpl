{if $mhFlash}<div class="alert {$mhFlash.type|escape}">{$mhFlash.message|escape}</div>{/if}
<div class="alert warning"><strong>Önemli:</strong> Trendyol için kategori/özellik/marka okuma ve ürün oluşturma hazırdır (canlı hesapla henüz doğrulanmadı); Hepsiburada ve N11 için bağlantı testi ve stok/fiyat güncelleme vardır. Amazon, Pazarama ve Ozon için API henüz yoktur, bu pazaryerlerine ürün <b>gönderilmez</b>.</div>

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
{if $adapter.implemented && !$liveSubmit}<div class="alert info"><strong>Canlı gönderim kilidi kapalı.</strong> Ürünler hazırlanır, tüm zorunlu alanlar doğrulanır ve "Gönderime hazır" olarak işaretlenir; pazaryerine istek <b>gönderilmez</b>. Kilidi yalnızca site yöneticisi açabilir.</div>{/if}
<nav class="xh-tabs">{foreach ['general' => 'Genel', 'categories' => 'Kategori eşleme', 'attributes' => 'Özellikler & marka', 'products' => 'Ürünler', 'listings' => 'Gönderimler', 'errors' => 'Hatalar'] as $k => $t}<a class="{if $tab == $k}on{/if}" href="{$mhBase|escape}?view=account&amp;id={$acc.id_account|escape}&amp;tab={$k|escape}">{$t|escape}</a>{/foreach}</nav>

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
<div class="xh-card"><h3>Kategori eşleme</h3>
	{if $catalog}
	<p class="muted"><b>Otomatik eşle</b> sitedeki her kategoriyi pazaryerinin en alt kategorisine kendiliğinden bağlar. Ayrıca gönderimde her ürün <b>kendi adına göre</b> de eşleştirilir (ör. "Kadın Giyim" kategorisindeki elbise → pazaryerinde Elbise). Elle yaptığınız eşleme her zaman önceliklidir ve hatırlanır.</p>
	{if $mhCanManage}<div class="row">
		<form method="post" class="row" style="margin:0"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="category_auto_map"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn" type="submit">Otomatik eşle (tek tık)</button></form>
		<form method="post" class="row" style="margin:0" onsubmit="return confirm('Otomatik eşlemeler yeniden hesaplansın mı? Elle yapılmış (onaylı) eşlemeler korunur.')"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="category_auto_map"><input type="hidden" name="overwrite" value="1"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn ghost sm" type="submit">Tümünü yeniden eşle</button></form>
		<form method="post" class="row" style="margin:0"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="catalog_refresh"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn ghost sm" type="submit">Pazaryeri kategorilerini yenile</button></form>
	</div>{/if}
	<form method="get" class="row"><input type="hidden" name="view" value="account"><input type="hidden" name="id" value="{$acc.id_account|escape}"><input type="hidden" name="tab" value="categories"><input type="text" name="q" value="{$q|escape}" placeholder="Pazaryeri kategorisi ara (ör. elbise)" maxlength="80"><button class="btn ghost sm" type="submit">Ara</button></form>
	{if $search}{if $search.ok}<div style="max-height:260px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:6px;margin-bottom:10px">{foreach $search.items as $it}<div class="row" style="padding:3px 0"><code>{$it.id|escape}</code> <span style="flex:1">{$it.path|escape}</span>{if !$it.leaf}<span class="pill">üst kategori</span>{/if}</div>{foreachelse}<p class="muted">Sonuç yok.</p>{/foreach}</div>{else}<p class="muted">{$search.message|escape}</p>{/if}{/if}
	{else}<p class="muted">Bu pazaryeri için kategori listesi henüz okunamıyor; pazaryeri kategori kodunu elle girin.</p>{/if}
	{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="category_map_save"><input type="hidden" name="id_account" value="{$acc.id_account|escape}">
	{foreach $categories as $c}<div class="row" style="border-bottom:1px solid #f1f5f9;padding:4px 0"><span style="flex:1;min-width:200px">{$c.category_name|escape}{if isset($catMap[$c.id_category]) && $catMap[$c.id_category].remote_category_name}<br><small class="muted">→ {$catMap[$c.id_category].remote_category_name|escape}</small>{/if}</span><input type="text" style="max-width:120px" name="remote[{$c.id_category|escape}]" value="{if isset($catMap[$c.id_category])}{$catMap[$c.id_category].remote_category_id|escape}{/if}" placeholder="kod" maxlength="60"><input type="hidden" name="remote_name[{$c.id_category|escape}]" value="{if isset($catMap[$c.id_category])}{$catMap[$c.id_category].remote_category_name|escape}{/if}"></div>{/foreach}
	<p><button class="btn" type="submit">Eşlemeleri kaydet</button></p></form>{/if}
</div>
{/if}

{if $tab == 'attributes'}
<div class="xh-card"><h3>Kategori özellikleri</h3>
	<p class="muted">Pazaryeri bazı özellikleri zorunlu tutar (Renk, Cinsiyet, Menşei…). Gönderimde boş kalanlar ürün adı ve açıklamasından <b>otomatik</b> doldurulur (ör. adında "Siyah" geçen ürüne Renk: Siyah, "Kadın" geçene Cinsiyet: Kadın, Menşei: Türkiye). Her zaman aynı olması gerekenleri burada sabitleyin.</p>
	{if $attrCats}<form method="get" class="row"><input type="hidden" name="view" value="account"><input type="hidden" name="id" value="{$acc.id_account|escape}"><input type="hidden" name="tab" value="attributes"><select name="rc">{foreach $attrCats as $rid => $rname}<option value="{$rid|escape}"{if $rc == $rid} selected{/if}>{if $rname}{$rname|escape}{else}{$rid|escape}{/if}</option>{/foreach}</select><button class="btn ghost sm" type="submit">Özellikleri getir</button></form>{else}<p class="muted">Önce Kategori eşleme sekmesinden kategorileri eşleyin.</p>{/if}
	{if $attrs}{if $attrs.ok}{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="attribute_map_save"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><input type="hidden" name="rc" value="{$rc|escape}">
	{foreach $attrs.items as $at}<div class="row" style="border-bottom:1px solid #f1f5f9;padding:4px 0"><span style="flex:1;min-width:160px">{$at.name|escape}{if $at.required} <span class="pill">zorunlu</span>{/if}{if $at.varianter} <span class="pill">varyanttan</span>{/if}</span>
		{if $at.values}<select name="val[{$at.id|escape}]" style="max-width:220px"><option value="">— otomatik —</option>{foreach $at.values as $v}<option value="{$v.id|escape}"{if isset($attrMap[$at.id]) && $attrMap[$at.id].value_id == $v.id} selected{/if}>{$v.name|escape}</option>{/foreach}</select>{/if}
		{if $at.allow_custom}<input type="text" name="custom[{$at.id|escape}]" maxlength="190" placeholder="serbest değer" style="max-width:180px" value="{if isset($attrMap[$at.id]) && !$attrMap[$at.id].value_id}{$attrMap[$at.id].value_text|escape}{/if}">{/if}</div>{/foreach}
	<p><button class="btn" type="submit">Özellikleri kaydet</button></p></form>{/if}{else}<p class="muted">{$attrs.message|escape}</p>{/if}{/if}
</div>
<div class="xh-card"><h3>Marka eşleme</h3>
	<p class="muted">Gönderimde markanız pazaryeri marka listesinde <b>aynı adla</b> aranır ve bulunursa kendiliğinden eşlenir. Farklı adla kayıtlıysa kodu buraya girin.</p>
	{if $mhCanManage && $catalog}<form method="post" class="row"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="brand_auto"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn ghost sm" type="submit">Markaları otomatik bul</button></form>{/if}
	{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="brand_map_save"><input type="hidden" name="id_account" value="{$acc.id_account|escape}">
	{foreach $brands as $b}<div class="row"><span style="flex:1">{$b.brand_name|escape}{if isset($brandMap[$b.id_brand]) && $brandMap[$b.id_brand].remote_brand_name} <small class="muted">→ {$brandMap[$b.id_brand].remote_brand_name|escape}</small>{/if}</span><input type="text" style="max-width:140px" name="brand_remote[{$b.id_brand|escape}]" value="{if isset($brandMap[$b.id_brand])}{$brandMap[$b.id_brand].remote_brand_id|escape}{/if}" placeholder="marka kodu" maxlength="60"><input type="hidden" name="brand_remote_name[{$b.id_brand|escape}]" value="{if isset($brandMap[$b.id_brand])}{$brandMap[$b.id_brand].remote_brand_name|escape}{/if}"></div>{foreachelse}<p class="muted">Ürünlerinizde marka yok.</p>{/foreach}
	{if $brands}<p><button class="btn" type="submit">Marka eşlemelerini kaydet</button></p>{/if}</form>{/if}
</div>
{/if}

{if $tab == 'products'}
<div class="xh-card"><h3>Ürünler</h3>
	{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="queue_products"><input type="hidden" name="id_account" value="{$acc.id_account|escape}">
	{foreach $products as $p}<label class="chk"><input type="checkbox" name="ids[]" value="{$p.id_product|escape}"> {$p.product_name|escape} <span class="muted">{$p.stock_code|escape}</span>{if !isset($catMap[$p.id_category])} <span class="pill">{if $catalog}kategori ürün adından eşlenecek{else}kategori eşlenmemiş{/if}</span>{/if}</label>{foreachelse}<p class="muted">Gönderilecek ürün yok.</p>{/foreach}
	<p><button class="btn" type="submit">Seçilenleri gönderim kuyruğuna al</button>{if !empty($adapter.stock_price)} <button class="btn ghost" type="submit" name="mhAction" value="push_stock_price">Seçilenlerin stok/fiyatını gönder</button>{/if}</p></form>{/if}
</div>
{/if}

{if $tab == 'listings'}
<div class="xh-card"><h3>Gönderimler</h3>
	{if $mhCanManage}<form method="post"><input type="hidden" name="token" value="{$mhToken|escape}"><input type="hidden" name="mhAction" value="retry_failed"><input type="hidden" name="id_account" value="{$acc.id_account|escape}"><button class="btn ghost sm" type="submit">Başarısız / hazır / engellenenleri yeniden kuyruğa al</button></form>{/if}
	{include file="file:{$mhTplDir}mh_listings.tpl"}
</div>
{/if}

{if $tab == 'errors'}<div class="xh-card"><h3>Hatalar</h3>{include file="file:{$mhTplDir}mh_errors.tpl"}</div>{/if}
{/if}

{if $view == 'listings'}<div class="xh-card"><h2>Gönderimler</h2><p><a href="{$mhBase|escape}">← Hesaplar</a></p>{include file="file:{$mhTplDir}mh_listings.tpl"}</div>{/if}
{if $view == 'errors'}<div class="xh-card"><h2>Hata geçmişi</h2><p><a href="{$mhBase|escape}">← Hesaplar</a></p>{include file="file:{$mhTplDir}mh_errors.tpl"}</div>{/if}
