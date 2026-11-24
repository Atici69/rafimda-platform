<div class="rv-card">
	<h2>Sosyal Medya</h2>
	<div class="rv-alert info">{if $rsoPublish}Otomatik yayın açık; yalnızca bağlı hesap ve resmî API ile çalışır.{else}<strong>Otomatik yayın kapalı.</strong> İçerik hazırlayıp önizleyebilir, takvime koyabilir ve elle paylaşmak için indirebilirsiniz.{/if}
		Saatler Türkiye saatidir (şu an {$rsoNowTr}).{if $rsoLimit && $rsoLimit.max} Bu ay {$rsoLimit.used}/{$rsoLimit.max} içerik.{/if}</div>
	{if $rsoPost}
		<p><a href="?s=social">← Tüm içerikler</a></p>
		<div class="rv-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
			<div>{if $rsoPost.media_list.type|default:'' == 'video'}<video src="{$rsoPost.media_urls[0]}" controls muted playsinline style="width:100%;border-radius:10px;background:#000"></video>
				{else}{foreach $rsoPost.media_urls as $u}<img src="{$u}" alt="Önizleme" style="width:{if $rsoPost.media_list.type|default:'' == 'storyboard'}31%{else}100%{/if};border-radius:10px;margin:2px">{/foreach}{/if}
				<p>{foreach $rsoPost.media_urls as $u}<a href="{$u}" download>İndir</a> {/foreach}</p></div>
			<div>
				<p><span class="pill {if $rsoPost.status == 'ready' || $rsoPost.status == 'published'}ok{elseif $rsoPost.status == 'failed'}bad{else}warn{/if}">{$rsoStatuses[$rsoPost.status]}</span> · {$rsoPlatforms[$rsoPost.platform]} · {$rsoFormats[$rsoPost.format][0]}{if $rsoPost.scheduled_at} · {$rsoPost.scheduled_at|truncate:16:''}{/if}</p>
				{if $rsoPost.last_error}<div class="rv-alert warning">{$rsoPost.last_error}</div>{/if}
				{if $rsoCanManage && $rsoPost.status != 'published' && $rsoPost.status != 'cancelled'}
				<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="rsoAction" value="edit"><input type="hidden" name="id_post" value="{$rsoPost.id_post}">
					<label for="cap">Metin</label><textarea id="cap" name="caption" rows="7">{$rsoPost.caption}</textarea>
					<label for="tag">Hashtag</label><input id="tag" name="hashtags" value="{$rsoPost.hashtags}"><p><button class="rv-btn" type="submit">Kaydet</button></p></form>
				<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="rsoAction" value="schedule"><input type="hidden" name="id_post" value="{$rsoPost.id_post}">
					<label for="when">Yayın zamanı</label><input id="when" type="datetime-local" name="when"><p><button class="rv-btn navy" type="submit">Takvime koy</button></p></form>
				<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="rsoAction" value="cancel"><input type="hidden" name="id_post" value="{$rsoPost.id_post}"><button class="rv-btn ghost" type="submit">Yayını iptal et</button></form>
				{else}<pre style="white-space:pre-wrap">{$rsoPost.caption}
{$rsoPost.hashtags}</pre>{/if}
			</div>
		</div>
	{else}
		{if $rsoCanManage}
		<h3>Üründen içerik oluştur</h3>
		<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="rsoAction" value="create">
			<label for="sp">Ürün</label><select id="sp" name="id_product">{foreach $rsoProducts as $p}<option value="{$p.id_product}">{$p.product_name} — {$p.price|string_format:'%.2f'} ₺</option>{foreachelse}<option value="0">Aktif ürününüz yok</option>{/foreach}</select>
			<fieldset class="rv-cats"><legend>Biçimler</legend>{foreach $rsoFormats as $k => $f}<label class="rv-chip"><input type="checkbox" name="formats[]" value="{$k}"> {$f[0]}</label>{/foreach}</fieldset>
			<label for="sw">Yayın zamanı (boş = taslak)</label><input id="sw" type="datetime-local" name="when">
			<p><button class="rv-btn" type="submit">Oluştur ve önizle</button></p>
		</form>
		{/if}
		<h3>Takvim ({if $rsoView == 'week'}bu hafta{else}{$rsoView}{/if})</h3>
		<p><a href="?s=social&view=week&d={$rsoPrev}">‹ Önceki</a> · <a href="?s=social&view=week&d={$rsoNext}">Sonraki ›</a></p>
		{if $rsoCalendar}<div class="rv-resp"><table class="rv-table"><tbody>{foreach $rsoCalendar as $c}<tr><td>{$c.scheduled_at|truncate:16:''}</td><td>{$rsoPlatforms[$c.platform]}</td><td>{$c.product_name}</td><td>{$rsoStatuses[$c.status]}</td><td><a href="?s=social&post={$c.id_post}">Aç</a></td></tr>{/foreach}</tbody></table></div>{else}<p style="color:var(--rv-muted)">Bu hafta planlı içerik yok.</p>{/if}
		<h3>Tüm içerikler</h3>
		{if $rsoList}<div class="rv-resp"><table class="rv-table"><thead><tr><th>Ürün</th><th>Biçim</th><th>Zaman</th><th>Durum</th><th></th></tr></thead><tbody>{foreach $rsoList as $s}<tr><td>{$s.product_name}</td><td>{$rsoFormats[$s.format][0]}</td><td>{$s.scheduled_at|default:'—'|truncate:16:''}</td><td>{$rsoStatuses[$s.status]}</td><td><a href="?s=social&post={$s.id_post}">Aç</a></td></tr>{/foreach}</tbody></table></div>{else}<p style="color:var(--rv-muted)">Henüz içerik yok.</p>{/if}
		<h3>Mağaza hesaplarım</h3>
		<div class="rv-resp"><table class="rv-table"><tbody>{foreach $rsoAccounts as $pl => $a}<tr><td>{$a.label}</td><td>{if $a.status == 'connected'}<span class="pill ok">bağlı</span>{elseif $a.status == 'error'}<span class="pill bad">hata</span>{else}<span class="pill off">bağlı değil</span>{/if}{if $a.last_error}<br><small>{$a.last_error}</small>{/if}</td>
			<td>{if $rsoCanManage}<form method="post" style="display:inline"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="rsoAction" value="connect"><input type="hidden" name="platform" value="{$pl}"><button class="rv-btn ghost" type="submit">Resmî OAuth ile bağla</button></form>{/if}</td></tr>{/foreach}</tbody></table></div>
		<p style="color:var(--rv-muted);font-size:13px">Şifreniz istenmez; hesabınızı platformun kendi sayfasında yetkilendirirsiniz. Resmî yayın entegrasyonu henüz tamamlanmadı.</p>
	{/if}
</div>
