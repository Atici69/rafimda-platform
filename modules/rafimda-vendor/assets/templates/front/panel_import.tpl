<p><a href="{$listUrl}">← Ürün listesi</a></p>
<div class="rv-card" style="max-width:820px"><h3>CSV ile toplu ürün yükleme</h3>
	<p>Şablonu indirin, doldurun ve yükleyin (UTF-8, ; veya , ayraçlı, en fazla {$maxRows} satır / 2 MB). <strong>stok_kodu</strong> sizin mevcut bir ürününüzle eşleşirse yalnızca fiyat ve stok güncellenir; aksi halde yeni ürün oluşturulur (onay kuralına tabi).</p>
	<p><a class="rv-btn ghost sm" href="{$templateUrl}">Şablonu indir</a></p>
	<form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="import_csv">
		<input type="file" name="csv" accept=".csv,.txt,text/csv" required><p><button class="rv-btn" type="submit">Yükle ve işle</button></p></form>
</div>
<div class="rv-grid c2">
	<div class="rv-card"><h3>Kategori numaraları</h3><table><tbody>{foreach $categories as $c}<tr><td>{$c.id_category}</td><td>{$c.category_name}</td></tr>{/foreach}</tbody></table></div>
	<div class="rv-card"><h3>Marka numaraları</h3><table><tbody>{foreach $brands as $b}<tr><td>{$b.id_brand}</td><td>{$b.brand_name}</td></tr>{/foreach}</tbody></table></div>
</div>
<div class="rv-card"><h3>Son yüklemeler</h3>
	{if $history}<div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Dosya</th><th>Yeni</th><th>Güncellenen</th><th>Hatalı</th></tr></thead><tbody>{foreach $history as $h}<tr><td>{$h.date_add}</td><td>{$h.file_name}</td><td>{$h.rows_created}</td><td>{$h.rows_updated}</td><td>{$h.rows_failed}</td></tr>{/foreach}</tbody></table></div>{else}<p style="color:var(--rv-muted)">Henüz yükleme yok.</p>{/if}
</div>
