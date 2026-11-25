<link rel="stylesheet" href="{$xhCss|escape}">
<div class="xh">
{if $xhPending}
<div class="alert warning"><form method="post" class="row" style="margin:0"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="xhAction" value="migrate">
	<span>Bekleyen migration: <strong>{implode(', ', $xhPending)|escape}</strong>. Çalıştırmadan önce veritabanı yedeği alın.</span><button class="btn navy sm" type="submit">Çalıştır</button></form></div>
{else}
<div class="xh-card"><strong>Zamanlanmış görev (cron):</strong>
	{if $xhCronUrl}<div class="muted">Otomatik senkronizasyon için sunucu cron'unun bu adresi dakikada bir çağırması gerekir (çağrılmazsa zamanlanmış güncellemeler ÇALIŞMAZ):</div><code style="word-break:break-all">{$xhCronUrl|escape}</code>{else}<div class="muted">Anahtar henüz yok; modülü yeniden kurun.</div>{/if}</div>
{if $imgHealth && (!$imgHealth.writable || $imgHealth.missing > 0)}<div class="alert {if !$imgHealth.writable}danger{else}warning{/if}"><strong>Ürün görselleri:</strong>
	{if !$imgHealth.dir_exists}<b>img/products</b> klasörü yok.{elseif !$imgHealth.writable}<b>img/products</b> klasörüne yazılamıyor — yeni görseller kaydedilemez. cPanel → Dosya Yöneticisi'nden klasör izinlerini 755 yapın.{/if}
	{if $imgHealth.missing > 0} {$imgHealth.missing|escape} görsel kaydının dosyası sunucuda yok (sitede yer tutucu görünür).{if $imgHealth.repairable > 0} {$imgHealth.repairable|escape} ürün XML kaynağından yeniden indirilebilir.{/if}{/if}
	{if $imgHealth.repairable > 0 && $imgHealth.writable}<form method="post" class="row" style="margin:6px 0 0"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="cmAction" value="image_repair"><button class="btn navy sm" type="submit">Eksik görselleri yeniden indir (30 ürün)</button></form>{/if}</div>{/if}
<details class="xh-card"{if $cmOpen || $cmPending} open{/if}><summary><strong>Otomatik kategori eşleştirme</strong>{if $cmPending} <span class="pill">{count($cmPending)} ürün onay bekliyor</span>{/if}</summary>
	<p class="muted">XML ve Excel aktarımlarında tedarikçi kategorisi (ya da ürün adı) sitedeki kategoriye kendiliğinden bağlanır. Emin olunamayanlar aşağıda onaya düşer; düzeltmeniz hatırlanır. Satıcılar kendi ürünlerini satıcı panelindeki "Kategori kontrolü" ekranından yönetir.</p>
	<form method="post" class="row" style="flex-wrap:wrap;gap:8px"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="cmAction" value="settings">
		<label>Yedek kategori (eşleşme yoksa)<select name="fallback_category"><option value="0">— yok (eşleşmeyen ürün aktarılmaz) —</option>{foreach $cmCategories as $c}<option value="{$c.id_category|escape}"{if $c.id_category == $cmFallbackCategory} selected{/if}>{$c.category_name|escape}</option>{/foreach}</select></label>
		<label>Yedek marka (marka boşsa)<select name="fallback_brand"><option value="0">— yok —</option>{foreach $cmBrands as $b}<option value="{$b.id_brand|escape}"{if $b.id_brand == $cmFallbackBrand} selected{/if}>{$b.brand_name|escape}</option>{/foreach}</select></label>
		<label class="chk"><input type="checkbox" name="create_brands" value="1"{if $cmCreateBrands} checked{/if}> Sitede olmayan markayı otomatik aç</label>
		<button class="btn navy sm" type="submit">Kaydet</button></form>
	{if $cmPending}
	<form method="post" class="row"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="cmAction" value="review_all"><button class="btn ghost sm" type="submit">"Orta" güvenlilerin hepsini onayla</button></form>
	<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Ürün</th><th>Kaynak kategori</th><th>Atanan</th><th>Güven</th><th></th></tr></thead><tbody>
	{foreach $cmPending as $r}<tr><td>{$r.current_name|escape}</td><td>{$r.source_text|escape}</td><td>{$r.path|escape}</td><td>{$r.confidence|escape} ({($r.score*100)|string_format:"%d"}%)</td>
		<td><form method="post" class="row" style="margin:0"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="cmAction" value="review_save"><input type="hidden" name="id_product" value="{$r.id_product|escape}"><select name="id_category">{foreach $cmCategories as $c}<option value="{$c.id_category|escape}"{if $c.id_category == $r.current_category} selected{/if}>{$c.category_name|escape}</option>{/foreach}</select><button class="btn sm" type="submit">Kaydet</button></form></td></tr>{/foreach}
	</tbody></table></div>{/if}
</details>
{include file="file:{$xhTplDir}xh_content.tpl"}
{/if}
</div>
<script src="{$xhJs|escape}"></script>
