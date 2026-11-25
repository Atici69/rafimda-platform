<p><a href="{$listUrl}">← Ürün listesi</a></p>
<div class="rv-card" style="max-width:820px"><h3>Excel / CSV ile toplu ürün yükleme</h3>
	<p>Şablonu indirin, doldurun ve yükleyin: <strong>Excel (.xlsx)</strong> en fazla {$maxRowsXlsx|default:2000} satır / 5 MB, <strong>CSV</strong> (UTF-8, ; veya ,) en fazla {$maxRows} satır / 2 MB.</p>
	<ul style="margin:0 0 10px 18px;font-size:13px">
		<li><strong>kategori</strong>: kategori adı ya da yolu yazın (ör. <code>Elektronik &gt; Telefon &gt; Telefon Kılıfı</code> veya <code>Kadın Tişört</code>); otomatik eşleştirilir. Numara da yazabilirsiniz.</li>
		<li><strong>marka</strong>: marka adı (sitede yoksa otomatik oluşturulur) ya da numarası.</li>
		<li><strong>stok_kodu</strong> sizin mevcut bir ürününüzle eşleşirse yalnızca fiyat ve stok güncellenir; aksi hâlde yeni ürün oluşturulur (onay kuralına tabi).</li>
		<li><strong>gorsel_url</strong>: bir veya birden çok https bağlantısı (virgülle); en fazla 5 görsel indirilir. <strong>kdv</strong>: oran (ör. 20).</li>
	</ul>
	<p><a class="rv-btn ghost sm" href="{$templateXlsxUrl|default:$templateUrl}">Excel şablonunu indir</a> <a class="rv-btn ghost sm" href="{$templateUrl}">CSV şablonunu indir</a></p>
	<form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="import_csv">
		<input type="file" name="csv" accept=".xlsx,.csv,.txt,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><p><button class="rv-btn" type="submit">Yükle ve işle</button></p></form>
	<p style="color:var(--rv-muted);font-size:12px">Eşleşmeyen kategoriler hatalı satır olarak raporlanır ve yöneticinin onayına öneri olarak düşer; onaylandıktan sonra aynı dosyayı tekrar yüklemeniz yeterlidir.</p>
</div>
<div class="rv-grid c2">
	<div class="rv-card"><h3>Kategori numaraları (isteğe bağlı)</h3><table><tbody>{foreach $categories as $c}<tr><td>{$c.id_category}</td><td>{$c.category_name}</td></tr>{/foreach}</tbody></table></div>
	<div class="rv-card"><h3>Marka numaraları</h3><table><tbody>{foreach $brands as $b}<tr><td>{$b.id_brand}</td><td>{$b.brand_name}</td></tr>{/foreach}</tbody></table></div>
</div>
<div class="rv-card"><h3>Son yüklemeler</h3>
	{if $history}<div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Dosya</th><th>Yeni</th><th>Güncellenen</th><th>Hatalı</th></tr></thead><tbody>{foreach $history as $h}<tr><td>{$h.date_add}</td><td>{$h.file_name}</td><td>{$h.rows_created}</td><td>{$h.rows_updated}</td><td>{$h.rows_failed}</td></tr>{/foreach}</tbody></table></div>{else}<p style="color:var(--rv-muted)">Henüz yükleme yok.</p>{/if}
</div>
