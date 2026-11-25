<div class="xh">
{if $xhFlash}<div class="alert {$xhFlash.type|escape}">{$xhFlash.message|escape}</div>{/if}
{if $view != 'conn'}
<nav class="xh-tabs">
	{foreach $xhTitles as $k => $t}<a class="{if $view == $k}on{/if}" href="{$xhBase|escape}?view={$k|escape}">{$t|escape}</a>{/foreach}
</nav>
{/if}

{if $view == 'export'}
<div class="xh-card"><h2>XML dışa aktarma</h2>
	<p class="muted">Onaylı ve aktif ürünlerinizi gizli bir adresten XML olarak yayınlayın. Adres bir parola gibi saklanmalıdır; ele geçerse beslemeyi iptal edip yenisini oluşturun.</p>
	{if $newUrl}<div class="alert success"><strong>Yeni besleme adresi (yalnızca bir kez gösterilir):</strong><br><code style="word-break:break-all">{$newUrl|escape}</code></div>{/if}
	{if $xhCanManage}<form method="post" class="row"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="export_create">
		<input type="text" name="name" placeholder="Besleme adı" maxlength="120" required> <label><input type="checkbox" name="only_in_stock" value="1"> Yalnızca stokta olanlar</label> <button class="btn" type="submit">Besleme oluştur</button></form>{/if}
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Ad</th><th>Adres</th><th>Durum</th><th>İstek</th><th>Son istek</th><th></th></tr></thead><tbody>
	{foreach $feeds as $f}<tr><td>{$f.name|escape}</td><td><code>…?t={$f.token_hint|escape}…</code></td><td><span class="pill {if $f.active}ok{else}warn{/if}">{if $f.active}Aktif{else}İptal{/if}</span></td><td>{$f.hits|escape}</td><td>{$f.last_hit|escape}</td>
		<td>{if $f.active && $xhCanManage}<form method="post" style="margin:0"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="export_revoke"><input type="hidden" name="id_feed" value="{$f.id_feed|escape}"><button class="btn ghost sm" type="submit">İptal et</button></form>{/if}</td></tr>{foreachelse}<tr><td colspan="6" class="muted">Henüz besleme yok.</td></tr>{/foreach}
	</tbody></table></div>
</div>
{/if}

{if $view == 'changes'}
<div class="xh-card"><h2>Fiyat, stok ve içerik değişimleri</h2>
	<p class="muted">XML senkronizasyonunun ürünlerinizde yaptığı değişikliklerin kaydı (yeniden eskiye).</p>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tarih</th><th>Tür</th><th>Ürün</th><th>Bağlantı</th><th>Değişim</th></tr></thead><tbody>
	{foreach $changes as $c}<tr><td>{$c.date_add|escape}</td><td><span class="pill">{$c.kind|escape}</span></td><td>{$c.product_name|escape} <span class="muted">#{$c.id_product|escape}</span></td><td>{$c.conn_name|escape}</td><td>{$c.detail|escape}</td></tr>{foreachelse}<tr><td colspan="5" class="muted">Henüz değişiklik kaydı yok.</td></tr>{/foreach}
	</tbody></table></div>
</div>
{/if}

{if $view == 'list'}
<div class="xh-card">
	<div class="row"><h2 style="margin:0">XML bağlantılarım</h2><span style="flex:1"></span>
		{if $xhCanManage}<form method="post" style="margin:0"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="queue_tick"><button class="btn ghost sm" type="submit">Kuyruğu şimdi işle ({$queue|escape})</button></form>
		<a class="btn" href="{$xhBase|escape}?view=new">+ Yeni XML</a>{/if}</div>
	<p class="muted">Tedarikçi XML'lerinizi bağlayın, alanları eşleştirin, ürünleri içe aktarın; fiyat ve stok otomatik güncellensin. Yeni ürünler{if !$xhAdmin} yönetici onayından sonra{/if} yayına girer.</p>
	{if $conns}<div class="scroll"><table><thead><tr><th>Ad</th>{if $xhAdmin}<th>Sahip</th>{/if}<th>Tedarikçi</th><th>Kaynak</th><th>Durum</th><th>Sıklık</th><th>Son çalışma</th><th></th></tr></thead><tbody>
	{foreach $conns as $c}<tr>
		<td><strong>{$c.name|escape}</strong>{if $c.last_error} <div class="muted" style="color:#b91c1c">{$c.last_error|escape}</div>{/if}</td>
		{if $xhAdmin}<td>{if $c.id_vendor > 0}Satıcı #{$c.id_vendor|escape}{else}Platform{/if}</td>{/if}
		<td>{$c.supplier_name|default:'—'|escape}</td>
		<td>{if $c.source_type == 'url'}URL{else}Dosya{/if}</td>
		<td><span class="pill {if $c.status == 'active'}ok{elseif $c.status == 'error'}bad{elseif $c.status == 'paused'}warn{/if}">{if $c.status == 'draft'}Taslak{elseif $c.status == 'active'}Aktif{elseif $c.status == 'paused'}Duraklatıldı{else}Hatalı{/if}</span></td>
		<td>{if $c.interval_minutes > 0}{$c.interval_minutes|escape} dk{else}Elle{/if}</td>
		<td>{$c.last_run_at|default:'—'|escape}</td>
		<td><a class="btn ghost sm" href="{$xhBase|escape}?view=conn&amp;id={$c.id_connection|escape}">Aç</a></td></tr>{/foreach}
	</tbody></table></div>{else}<p>Henüz bağlantı yok.</p>{/if}
</div>
{/if}

{if $view == 'new'}
<div class="xh-card" style="max-width:760px"><h2>Yeni XML bağlantısı</h2>
	{if !$xhCanManage}<div class="alert warning">Bu işlem için yetkiniz yok.</div>{else}
	<form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="conn_create">
		<label>Bağlantı adı</label><input type="text" name="name" maxlength="120" required>
		<label>Tedarikçi (isteğe bağlı)</label><select name="id_supplier"><option value="0">—</option>{foreach $suppliers as $s}<option value="{$s.id_supplier|escape}">{$s.name|escape}</option>{/foreach}</select>
		<label>Kaynak türü</label><select name="source_type" id="xh-src"><option value="url">URL (uzak XML)</option><option value="file">Dosya yükle</option></select>
		<div id="xh-url"><label>XML adresi</label><input type="url" name="url" maxlength="1000" placeholder="https://tedarikci.com/feed.xml"><div class="muted">Yalnızca genel internet adresleri (http/https; 80, 443, 8080, 8443). Yerel/özel ağ adresleri engellenir.</div></div>
		<div id="xh-file" style="display:none"><label>XML dosyası (en fazla 50 MB)</label><input type="file" name="xml_file" accept=".xml,text/xml,application/xml"><div class="muted">DOCTYPE/ENTITY içeren dosyalar güvenlik nedeniyle reddedilir.</div></div>
		<label>Otomatik güncelleme sıklığı</label><select name="interval_minutes">{foreach $intervals as $k => $l}<option value="{$k|escape}">{$l|escape}</option>{/foreach}</select>
		<div class="muted">Otomatik güncelleme için sunucuda zamanlanmış görev (cron) kurulu olmalıdır.</div>
		<h3 style="margin-top:16px">Ürün işleme ayarları</h3>
		{include file="file:{$xhTplDir}xh_options.tpl"}
		<p><button class="btn" type="submit">Oluştur ve test et</button></p>
	</form>{/if}
</div>
{/if}

{if $view == 'suppliers'}
<div class="grid g2">
<div class="xh-card"><h2>Tedarikçiler</h2>{if $suppliers}<table><thead><tr><th>Ad</th><th>Web</th><th></th></tr></thead><tbody>{foreach $suppliers as $s}<tr><td>{$s.name|escape}</td><td>{$s.website|escape}</td><td><a class="btn ghost sm" href="{$xhBase|escape}?view=suppliers&amp;id={$s.id_supplier|escape}">Düzenle</a></td></tr>{/foreach}</tbody></table>{else}<p>Henüz tedarikçi yok.</p>{/if}</div>
{if $xhCanManage}<div class="xh-card"><h3>{if $edit}Tedarikçiyi düzenle{else}Yeni tedarikçi{/if}</h3>
	<form method="post"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="supplier_save"><input type="hidden" name="id_supplier" value="{$edit.id_supplier|default:0|escape}">
		<label>Ad</label><input type="text" name="name" value="{$edit.name|default:''|escape}" maxlength="120" required>
		<label>Web sitesi</label><input type="text" name="website" value="{$edit.website|default:''|escape}" maxlength="190">
		<label>Not</label><textarea name="notes" rows="3" maxlength="500">{$edit.notes|default:''|escape}</textarea>
		<p><button class="btn" type="submit">Kaydet</button></p></form></div>{/if}
</div>
{/if}

{if $view == 'runs'}
<div class="xh-card"><h2>İçe aktarma ve senkronizasyon geçmişi</h2>
	{if $runs}<div class="scroll"><table><thead><tr><th>#</th><th>Bağlantı</th><th>Tür</th><th>Durum</th><th>İlerleme</th><th>Sonuç</th><th>Tarih</th><th></th></tr></thead><tbody>
	{foreach $runs as $r}<tr><td>{$r.id_run|escape}</td><td>{$r.conn_name|escape}</td><td>{if $r.kind == 'import'}İçe aktarma{elseif $r.kind == 'sync'}Senkronizasyon{else}Yeniden deneme{/if}</td>
		<td><span class="pill {if $r.status == 'done'}ok{elseif $r.status == 'failed'}bad{elseif $r.status == 'cancelled'}warn{/if}">{if $r.status == 'queued'}Sırada{elseif $r.status == 'running'}Çalışıyor{elseif $r.status == 'done'}Bitti{elseif $r.status == 'failed'}Başarısız{else}İptal{/if}</span>{if $r.attempts > 0 && $r.status == 'queued'} <span class="muted">deneme {$r.attempts|escape}</span>{/if}</td>
		<td>{if $r.total > 0}<div class="bar"><i style="width:{($r.item_offset / $r.total * 100)|string_format:'%d'}%"></i></div><span class="muted">{$r.item_offset|escape} / {$r.total|escape}</span>{else}—{/if}</td>
		<td class="muted">{$r.message|escape}</td><td class="muted">{$r.date_add|escape}</td>
		<td>{if $xhCanManage && ($r.status == 'queued' || $r.status == 'running')}<form method="post" style="margin:0"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="run_cancel"><input type="hidden" name="id_run" value="{$r.id_run|escape}"><button class="btn ghost sm" type="submit">İptal</button></form>{/if}</td></tr>{/foreach}
	</tbody></table></div>{else}<p>Henüz işlem yok.</p>{/if}
	{if $xhCanManage}<form method="post"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="queue_tick"><button class="btn navy sm" type="submit">Kuyruğu şimdi işle</button></form>{/if}
</div>
{/if}

{if $view == 'errors'}
<div class="xh-card"><h2>Hata ve uyarı kayıtları</h2>
	{if $errors}<div class="scroll"><table><thead><tr><th>Tarih</th><th>Bağlantı</th><th>Ürün anahtarı</th><th>Düzey</th><th>Mesaj</th></tr></thead><tbody>
	{foreach $errors as $e}<tr><td class="muted">{$e.date_add|escape}</td><td>{$e.conn_name|escape}</td><td>{$e.item_key|escape}</td><td><span class="pill {if $e.level == 'error'}bad{else}warn{/if}">{if $e.level == 'error'}Hata{else}Uyarı{/if}</span></td><td>{$e.message|escape}</td></tr>{/foreach}
	</tbody></table></div>{else}<p>Kayıt yok.</p>{/if}
</div>
{/if}

{if $view == 'conn'}
<p><a href="{$xhBase|escape}?view=list">← Bağlantılar</a></p>
<h2 style="color:#0b2545;margin:0 0 4px">{$conn.name|escape} <span class="pill {if $conn.status == 'active'}ok{elseif $conn.status == 'error'}bad{elseif $conn.status == 'paused'}warn{/if}">{$conn.status|escape}</span></h2>
<p class="muted">{if $conn.source_type == 'url'}{$conn.url|escape}{else}Yüklenen dosya{/if}{if $conn.item_path} · ürün düğümü: <code>{$conn.item_path|escape}</code>{/if}</p>
<nav class="xh-tabs">
	{foreach ['general' => 'Genel', 'mapping' => 'Alan eşleştirme', 'maps' => 'Marka/Kategori', 'preview' => 'Önizleme ve içe aktarma', 'history' => 'Geçmiş', 'errors' => 'Hatalar'] as $k => $t}<a class="{if $tab == $k}on{/if}" href="{$xhBase|escape}?view=conn&amp;id={$conn.id_connection|escape}&amp;tab={$k|escape}">{$t|escape}</a>{/foreach}
</nav>
{if $conn.last_error}<div class="alert danger">Son hata: {$conn.last_error|escape}</div>{/if}

{if $tab == 'general'}
<div class="grid g2">
<div class="xh-card"><h3>Durum</h3>
	<p>Ürün satırı: {foreach $counts as $st => $n}<span class="pill">{$st|escape}: {$n|escape}</span> {foreachelse}<span class="muted">henüz yok</span>{/foreach}</p>
	{if $xhCanManage}
	<form method="post" class="row"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="conn_test"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}"><button class="btn navy sm" type="submit">Bağlantıyı test et{if $conn.source_type == 'url'} (örnek indir){/if}</button></form>
	<div class="row" style="margin-top:10px">
	{foreach ['import' => 'Tümünü içe aktar', 'sync' => 'Fiyat/stok senkronize et', 'retry' => 'Hatalıları yeniden dene'] as $k => $t}
		<form method="post" style="margin:0"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="run_enqueue"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}"><input type="hidden" name="kind" value="{$k|escape}"><button class="btn {if $k == 'import'}{else}ghost{/if} sm" type="submit">{$t|escape}</button></form>
	{/foreach}</div>
	<p class="muted">Kuyruğa alınan işlem zamanlanmış görevle (cron) ya da "Kuyruğu şimdi işle" ile ilerler.</p>{/if}
</div>
<div class="xh-card"><h3>Ayarlar</h3>
	{if $xhCanManage}<form method="post"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="conn_options_save"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}">
		<label>Ad</label><input type="text" name="name" value="{$conn.name|escape}" maxlength="120">
		<label>Tedarikçi</label><select name="id_supplier"><option value="0">—</option>{foreach $suppliers as $s}<option value="{$s.id_supplier|escape}"{if $s.id_supplier == $conn.id_supplier} selected{/if}>{$s.name|escape}</option>{/foreach}</select>
		<label>Otomatik güncelleme sıklığı</label><select name="interval_minutes">{foreach $intervals as $k => $l}<option value="{$k|escape}"{if $k == $conn.interval_minutes} selected{/if}>{$l|escape}</option>{/foreach}</select>
		{if $conn.status != 'draft'}<label>Durum</label><select name="status"><option value="active"{if $conn.status != 'paused'} selected{/if}>Aktif</option><option value="paused"{if $conn.status == 'paused'} selected{/if}>Duraklatıldı</option></select>{/if}
		{include file="file:{$xhTplDir}xh_options.tpl"}
		<p><button class="btn" type="submit">Kaydet</button></p></form>
	{if $conn.source_type == 'url'}<h4>Kimlik doğrulama (Basic Auth)</h4>
	<p class="muted">{if $hasAuth}Kayıtlı kimlik bilgisi var (şifreli saklanır, tekrar gösterilmez). Değiştirmek için yeni değer girin; kaldırmak için kullanıcı adını boş bırakıp kaydedin.{else}Beslemeniz kullanıcı adı/şifre istiyorsa girin. Yalnızca HTTPS adreslerinde kullanılır; başka sunucuya yönlendirmede gönderilmez.{/if}</p>
	<form method="post" autocomplete="off"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="conn_auth_save"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}">
		<div class="grid g2"><div><label>Kullanıcı adı</label><input type="text" name="auth_user" maxlength="120" autocomplete="off"></div><div><label>Şifre</label><input type="password" name="auth_pass" maxlength="200" autocomplete="new-password"></div></div>
		<p><button class="btn ghost sm" type="submit">Kimlik bilgisini kaydet</button></p></form>{/if}
	<form method="post" onsubmit="return confirm('Bağlantı ve XML Hub kayıtları silinsin mi? Ürünler silinmez.')"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="conn_delete"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}"><button class="btn danger sm" type="submit">Bağlantıyı sil</button></form>{/if}
</div></div>
{/if}

{if $tab == 'mapping'}
<div class="xh-card">
	{if !$sampleOk}<div class="alert warning">Henüz örnek veri yok. Genel sekmesinden "Bağlantıyı test et" ile XML'i alın.</div>
	{elseif !$conn.item_path}<div class="alert warning">Ürün düğümü bulunamadı. Aşağıya ürün yolunu elle girin (ör. <code>Catalog/Products/Product</code>).</div>{/if}
	{if isset($sampleError)}<div class="alert danger">{$sampleError|escape}</div>{/if}
	{if $xhCanManage}<form method="post"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="conn_mapping_save"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}">
	<label>Ürün düğümü yolu</label><input type="text" name="item_path" value="{$conn.item_path|escape}" maxlength="190">
	<datalist id="xh-keys">{foreach $keys as $k}<option value="{$k|escape}">{/foreach}</datalist>
	<h3 style="margin-top:14px">Alanlar</h3>
	<div class="muted">Kaynak alanı seçin ya da yazın. <code>a|b</code> = ilk dolu olan, <code>=Sabit</code> = sabit değer. {if $mapping.fields|count == 0 && isset($suggest)}Boş alanlar için öneriler gösterilir; kaydetmek önerileri uygular.{/if}</div>
	<div class="grid g2">
	{foreach $targets as $t => $def}
		<div><label>{$def[0]|escape}{if $def[1]} *{/if}</label><input type="text" list="xh-keys" name="fields[{$t|escape}]" value="{if isset($mapping.fields[$t])}{$mapping.fields[$t]|escape}{elseif isset($suggest.fields[$t])}{$suggest.fields[$t]|escape}{/if}" maxlength="190"></div>
	{/foreach}</div>
	<h3 style="margin-top:14px">Varyantlar</h3>
	<label>Varyant kayıt yolu</label><input type="text" list="xh-rec" name="variant_source" value="{if $mapping.variant_source|default:''}{$mapping.variant_source|escape}{elseif isset($suggest.variant_source)}{$suggest.variant_source|escape}{/if}" maxlength="190">
	<datalist id="xh-rec">{foreach $recordKeys|default:[] as $k}<option value="{$k|escape}">{/foreach}</datalist>
	<div class="grid g3">{foreach $vtargets as $t => $l}<div><label>{$l|escape}</label><input type="text" name="variant_fields[{$t|escape}]" value="{if isset($mapping.variant_fields[$t])}{$mapping.variant_fields[$t]|escape}{elseif isset($suggest.variant_fields[$t])}{$suggest.variant_fields[$t]|escape}{/if}" maxlength="190"></div>{/foreach}</div>
	{if $distinctCats}<h3 style="margin-top:14px">Kategori eşleştirme</h3><div class="muted">Tedarikçi kategorisini Rafımda kategorisine bağlayın. Eşleşmeyenler için ayarlardaki varsayılan kategori kullanılır.</div>
	<table><tbody>{foreach $distinctCats as $i => $txt}<tr><td>{$txt|escape}<input type="hidden" name="cat_text[{$i|escape}]" value="{$txt|escape}"></td><td><select name="cat_id[{$i|escape}]"><option value="0">—</option>{foreach $categories as $c}<option value="{$c.id_category|escape}"{if isset($mapping.category_map[$txt]) && $mapping.category_map[$txt] == $c.id_category} selected{/if}>{$c.category_name|escape}</option>{/foreach}</select></td></tr>{/foreach}</tbody></table>{/if}
	{if $distinctBrands}<h3 style="margin-top:14px">Marka eşleştirme</h3>
	<table><tbody>{foreach $distinctBrands as $i => $txt}<tr><td>{$txt|escape}<input type="hidden" name="brand_text[{$i|escape}]" value="{$txt|escape}"></td><td><select name="brand_id[{$i|escape}]"><option value="0">—</option>{foreach $brands as $b}<option value="{$b.id_brand|escape}"{if isset($mapping.brand_map[$txt]) && $mapping.brand_map[$txt] == $b.id_brand} selected{/if}>{$b.brand_name|escape}</option>{/foreach}</select></td></tr>{/foreach}</tbody></table>{/if}
	<p><button class="btn" type="submit">Eşleştirmeyi kaydet</button></p></form>{/if}
</div>
{/if}

{if $tab == 'maps'}
<div class="xh-card"><h3>Marka ve kategori eşleştirme</h3>
	<p class="muted">Tedarikçideki adı sitenizdeki marka/kategoriye bağlayın. Eşleştirdikten sonra hatalı satırları "Hatalıları yeniden dene" ile ekleyebilirsiniz.</p>
	{if $unmapped.brand|count == 0 && $unmapped.category|count == 0}<p class="muted">Eşleştirilecek ad yok. (Liste, içe aktarma/önizleme sonrası kayıtlı satırlardan çıkarılır.)</p>{/if}
	{if $xhCanManage}<form method="post"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="conn_map_save"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}">
	{if $unmapped.brand|count > 0}<h4>Markalar</h4>{foreach $unmapped.brand as $u}
		<div class="row"><span style="flex:1">{$u.text|escape} <span class="pill">{$u.n|escape} ürün</span></span><select name="map_brand[{$u.enc|escape}]"><option value="0">—</option>{foreach $brands as $b}<option value="{$b.id_brand|escape}">{$b.brand_name|escape}</option>{/foreach}</select></div>{/foreach}{/if}
	{if $unmapped.category|count > 0}<h4>Kategoriler</h4>{foreach $unmapped.category as $u}
		<div class="row"><span style="flex:1">{$u.text|escape} <span class="pill">{$u.n|escape} ürün</span></span><select name="map_category[{$u.enc|escape}]"><option value="0">—</option>{foreach $categories as $c}<option value="{$c.id_category|escape}">{$c.category_name|escape}</option>{/foreach}</select></div>{/foreach}{/if}
	{if $unmapped.brand|count > 0 || $unmapped.category|count > 0}<p><button class="btn" type="submit">Eşleştirmeleri kaydet</button></p>{/if}</form>{/if}
	{if $mapBrand|count > 0 || $mapCategory|count > 0}<h4>Kayıtlı eşleştirmeler</h4><p class="muted">{foreach $mapBrand as $k => $v}<span class="pill">Marka: {$k|escape} → #{$v|escape}</span> {/foreach}{foreach $mapCategory as $k => $v}<span class="pill">Kategori: {$k|escape} → #{$v|escape}</span> {/foreach}</p>{/if}
</div>
{/if}

{if $tab == 'preview'}
<div class="xh-card"><h3>İlk 20 ürün önizlemesi</h3>
	{if !$sampleOk || !$conn.item_path}<div class="alert warning">Önce bağlantıyı test edin ve alanları eşleştirin.</div>{/if}
	{if isset($sampleError)}<div class="alert danger">{$sampleError|escape}</div>{/if}
	{if $rows}
	<form method="post"><input type="hidden" name="token" value="{$xhToken|escape}"><input type="hidden" name="xhAction" value="run_enqueue"><input type="hidden" name="id_connection" value="{$conn.id_connection|escape}"><input type="hidden" name="kind" value="import">
	<div class="scroll"><table><thead><tr>{if $xhCanManage}<th></th>{/if}<th>Ürün</th><th>SKU</th><th>Fiyat</th><th>Stok</th><th>Kategori</th><th>Varyant</th><th>Görsel</th><th>İşlem</th></tr></thead><tbody>
	{foreach $rows as $r}<tr>{if $xhCanManage}<td>{if $r.key && !$r.errors}<input type="checkbox" name="sel[]" value="{$r.key|escape}">{/if}</td>{/if}
		<td>{$r.name|escape}{if $r.errors}<div class="muted" style="color:#b91c1c">{foreach $r.errors as $e}{$e|escape} {/foreach}</div>{/if}</td>
		<td>{$r.sku|escape}</td><td>{$r.price|string_format:"%.2f"}{if $r.old_price > 0} <span class="muted"><s>{$r.old_price|string_format:"%.2f"}</s></span>{/if}</td><td>{if $r.stock !== null}{$r.stock|escape}{else}—{/if}</td><td>{if $r.category_path}{$r.category_path|escape}{if $r.category_how == 'medium' || $r.category_how == 'low'} <span class="pill warn" title="Emin olunamadı; içe aktarmadan sonra onayınıza sunulur">kontrol</span>{elseif $r.category_how != 'map'} <span class="pill ok">otomatik</span>{/if}{else}—{/if}</td><td>{$r.variants|count}</td><td>{$r.images|count}</td>
		<td><span class="pill {if $r.action == 'error'}bad{elseif $r.action == 'update'}warn{else}ok{/if}">{if $r.action == 'error'}Hata{elseif $r.action == 'update'}Güncellenecek{else}Eklenecek{/if}</span></td></tr>{/foreach}
	</tbody></table></div>
	{if $xhCanManage}<div class="row" style="margin-top:12px"><button class="btn" type="submit" name="selected_only" value="1">Seçilenleri içe aktar</button><button class="btn navy" type="submit">Tümünü içe aktar</button></div>{/if}</form>{/if}
</div>
{/if}

{if $tab == 'history'}
<div class="xh-card"><h3>Bu bağlantının işlemleri</h3>
	{if $runs}<div class="scroll"><table><thead><tr><th>#</th><th>Tür</th><th>Durum</th><th>İlerleme</th><th>Sonuç</th><th>Tarih</th></tr></thead><tbody>
	{foreach $runs as $r}<tr><td>{$r.id_run|escape}</td><td>{$r.kind|escape}</td><td>{$r.status|escape}</td><td>{$r.item_offset|escape} / {$r.total|escape}</td><td class="muted">{$r.message|escape}</td><td class="muted">{$r.date_add|escape}</td></tr>{/foreach}</tbody></table></div>{else}<p>Henüz işlem yok.</p>{/if}
</div>
{/if}

{if $tab == 'errors'}
<div class="xh-card"><h3>Hata raporu</h3>
	{if $errors}<div class="scroll"><table><thead><tr><th>Tarih</th><th>Ürün</th><th>Düzey</th><th>Mesaj</th></tr></thead><tbody>{foreach $errors as $e}<tr><td class="muted">{$e.date_add|escape}</td><td>{$e.item_key|escape}</td><td>{$e.level|escape}</td><td>{$e.message|escape}</td></tr>{/foreach}</tbody></table></div>{else}<p>Kayıt yok.</p>{/if}
</div>
{/if}
{/if}
</div>
