<div class="rv-card">
	<h2>Vitrin ve öne çıkarma</h2>
	<div class="rv-alert info">Ürününüzü ana sayfa vitrininde (öne çıkan / kampanyalı) göstermek için talep açın. Talepler yönetici onayından sonra, seçtiğiniz tarih aralığında yayınlanır. Bu sürümde reklam ücreti/ödeme yoktur.</div>
	{if $can.edit}<form method="post" class="rv-toolbar" style="flex-wrap:wrap"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="showcase_request">
		<select name="slot_code" required style="width:auto">{foreach $slots as $s}<option value="{$s.code}">{$s.title}</option>{/foreach}</select>
		<select name="id_product" required style="max-width:280px"><option value="">Ürün seçin</option>{foreach $products as $p}<option value="{$p.id_product}">{$p.product_name}</option>{/foreach}</select>
		<label>Başlangıç <input type="date" name="starts_at"></label><label>Bitiş <input type="date" name="ends_at"></label>
		<button class="rv-btn" type="submit">Talep gönder</button></form>{/if}
</div>
<div class="rv-card"><h3>Taleplerim</h3>
	<table class="rv-resp"><thead><tr><th>Alan</th><th>Ürün</th><th>Tarih aralığı</th><th>Durum</th><th></th></tr></thead><tbody>
	{foreach $items as $i}<tr><td data-l="Alan">{$i.slot_title}</td><td data-l="Ürün">{$i.product_name}</td><td data-l="Tarih">{$i.starts_at} – {$i.ends_at}</td>
		<td data-l="Durum"><span class="pill {if $i.status == 'approved'}ok{elseif $i.status == 'pending'}warn{else}off{/if}">{if $i.status == 'approved'}Yayında/Onaylı{elseif $i.status == 'pending'}Onay bekliyor{elseif $i.status == 'rejected'}Reddedildi{else}Kaldırıldı{/if}</span>{if $i.note} <small style="color:var(--rv-muted)">{$i.note}</small>{/if}</td>
		<td>{if $can.edit && ($i.status == 'pending' || $i.status == 'approved')}<form method="post" style="margin:0"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="showcase_withdraw"><input type="hidden" name="id_item" value="{$i.id_item}"><button class="rv-btn ghost sm" type="submit" data-confirm="Talep geri çekilsin mi?">Geri çek</button></form>{/if}</td></tr>{foreachelse}<tr><td colspan="5" style="text-align:center;color:var(--rv-muted);padding:24px">Henüz talebiniz yok.</td></tr>{/foreach}
	</tbody></table>
</div>
