<p><a href="{$listUrl}">← Ürün listesi</a> · <a href="{$importUrl}">Excel / CSV yükleme</a></p>
<div class="rv-card"><h3>Kategori kontrolü</h3>
	<p>Excel ve XML aktarımında kategorisi otomatik bulunan ama sistemin <strong>emin olamadığı</strong> ürünler burada. Ürünler zaten önerilen kategoride yayına hazır; doğruysa onaylayın, yanlışsa doğru kategoriyi seçin. Yaptığınız seçim <strong>hatırlanır</strong>: aynı kategori adı bir daha geldiğinde doğrudan oraya gider ve aynı kategori adındaki diğer bekleyen ürünler de birlikte düzelir.</p>
	{if $rows}
	<form method="post" style="margin:0 0 12px"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="category_review_confirm_all"><button class="rv-btn ghost sm" type="submit">"Orta" güvenli önerilerin hepsini onayla</button></form>
	<div style="overflow-x:auto"><table><thead><tr><th>Ürün</th><th>Kaynak kategori</th><th>Önerilen / mevcut</th><th>Güven</th><th>İşlem</th></tr></thead><tbody>
	{foreach $rows as $r}
	<tr>
		<td>{$r.current_name}</td>
		<td>{if $r.source_text}{$r.source_text}{else}<span style="color:var(--rv-muted)">(yok — ürün adından)</span>{/if}<br><small style="color:var(--rv-muted)">{$r.origin}</small></td>
		<td>{$r.path}{if $r.alternatives}<br><small style="color:var(--rv-muted)">Diğer olasılıklar: {foreach $r.alternatives as $al}{$al.path}{if !$al@last}; {/if}{/foreach}</small>{/if}</td>
		<td>{if $r.confidence == 'medium'}Orta{elseif $r.confidence == 'low'}Düşük{else}{$r.confidence}{/if} <small>({($r.score*100)|string_format:"%d"}%)</small></td>
		<td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin:0"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="category_review_save"><input type="hidden" name="id_product" value="{$r.id_product}">
			<select name="id_category" style="max-width:240px">{foreach $categories as $c}<option value="{$c.id_category}"{if $c.id_category == $r.current_category} selected{/if}>{$c.category_name}</option>{/foreach}</select>
			<button class="rv-btn sm" type="submit">Kaydet</button></form></td>
	</tr>
	{/foreach}
	</tbody></table></div>
	{else}<p style="color:var(--rv-muted)">Kontrol bekleyen ürün yok. Tüm kategoriler otomatik ve güvenle eşleşti.</p>{/if}
</div>
