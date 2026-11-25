<p><a href="{$listUrl}">← Ürün listesi</a></p>
<div class="rv-card" style="max-width:820px"><h3>Excel / CSV ile toplu ürün yükleme</h3>
	<p>Excel şablonunu indirin, doldurun ve yükleyin (.xlsx veya UTF-8 .csv; en fazla {$maxRows} satır, Excel 5 MB / CSV 2 MB).</p>
	<ul style="margin:0 0 12px 18px;padding:0">
		<li><strong>Yeni ürün:</strong> <code>urun_id</code> boş; <code>urun_adi</code>, <code>kategori_id</code>, <code>marka_id</code>, <code>fiyat</code> zorunlu (onay kuralına tabi).</li>
		<li><strong>Fiyat/stok güncelleme:</strong> <code>urun_id</code> veya <code>stok_kodu</code> sizin ürününüzle eşleşirse yalnızca fiyat ve stok güncellenir; boş hücre mevcut değeri korur.</li>
		<li>Toplu güncelleme için önce <em>Ürünlerimi Excel'e aktar</em> ile dosyanızı indirin, fiyat/stok sütunlarını düzenleyip geri yükleyin. Tek seferde en fazla {$maxRows} satır işlenir; daha fazla ürününüz varsa dosyayı parçalara bölün.</li>
	</ul>
	<p style="display:flex;flex-wrap:wrap;gap:8px">
		<a class="rv-btn ghost sm" href="{$templateXlsxUrl}">Excel şablonunu indir</a>
		<a class="rv-btn ghost sm" href="{$exportXlsxUrl}">Ürünlerimi Excel'e aktar</a>
		<a class="rv-btn ghost sm" href="{$templateUrl}">CSV şablonu</a>
	</p>
	<form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="import_csv">
		<input type="file" name="csv" accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required><p><button class="rv-btn" type="submit">Yükle ve işle</button></p></form>
</div>
<div class="rv-grid c2">
	<div class="rv-card"><h3>Kategori numaraları</h3><div style="max-height:360px;overflow:auto"><table><tbody>{foreach $categories as $c}<tr><td>{$c.id_category}</td><td>{$c.category_name}</td></tr>{/foreach}</tbody></table></div></div>
	<div class="rv-card"><h3>Marka numaraları</h3><div style="max-height:360px;overflow:auto"><table><tbody>{foreach $brands as $b}<tr><td>{$b.id_brand}</td><td>{$b.brand_name}</td></tr>{/foreach}</tbody></table></div></div>
</div>
<div class="rv-card"><h3>Son yüklemeler</h3>
	{if $history}<div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Dosya</th><th>Yeni</th><th>Güncellenen</th><th>Hatalı</th></tr></thead><tbody>{foreach $history as $h}<tr><td>{$h.date_add}</td><td>{$h.file_name}</td><td>{$h.rows_created}</td><td>{$h.rows_updated}</td><td>{$h.rows_failed}</td></tr>{/foreach}</tbody></table></div>{else}<p style="color:var(--rv-muted)">Henüz yükleme yok.</p>{/if}
</div>
