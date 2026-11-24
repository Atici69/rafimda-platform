<div class="rv-card">
	<h2>Değerlendirmeler</h2>
	<p>Mağaza puanı: {if $rating.count}<strong>{$rating.avg}</strong> / 5 · {$rating.count} onaylı değerlendirme{else}henüz onaylı değerlendirme yok{/if}</p>
	<p style="color:var(--rv-muted)">Değerlendirmeler yönetici onayından sonra yayınlanır. "Doğrulanmış satın alma" etiketi yalnızca teslim edilmiş siparişe dayanan yorumlarda görünür.</p>
	<h3>Ürün değerlendirmeleri</h3>
	{if !$reviewsAvailable}<div class="rv-alert warning">Ürün yorum modülü (reviews) kurulu değil.</div>
	{elseif $reviews.product}
	{foreach $reviews.product as $r}
	<div class="rv-qa">
		<p class="rv-qa__meta"><strong>{$r.product_name}</strong> · <span aria-label="{$r.rating} / 5">{for $i=1 to 5}{if $i <= $r.rating}★{else}☆{/if}{/for}</span> · {$r.date_add}{if $r.verified} · <span class="pill ok">Doğrulanmış satın alma</span>{/if} · <span class="pill {if $r.active}ok{else}warn{/if}">{if $r.active}yayında{else}onay bekliyor{/if}</span>{if $r.helpful} · {$r.helpful} kişi faydalı buldu{/if}</p>
		<p class="rv-qa__q">{$r.comment}</p>
		{if $r.vendor_reply}<p class="rv-qa__a">↳ Yanıtınız: {$r.vendor_reply}</p>{/if}
		{if $canReply}<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="kind" value="product"><input type="hidden" name="id" value="{$r.id_review}">
			<label for="rep_p{$r.id_review}">{if $r.vendor_reply}Yanıtı güncelle{else}Herkese açık yanıt{/if}</label><textarea id="rep_p{$r.id_review}" name="reply" rows="2" maxlength="1500" required></textarea>
			<p><button class="rv-btn" type="submit">Yanıtla</button></p></form>{/if}
	</div>
	{/foreach}
	{else}<p style="color:var(--rv-muted)">Henüz ürün değerlendirmesi yok.</p>{/if}
	<h3>Mağaza değerlendirmeleri</h3>
	{if $reviews.store}
	{foreach $reviews.store as $r}
	<div class="rv-qa">
		<p class="rv-qa__meta"><span aria-label="{$r.rating} / 5">{for $i=1 to 5}{if $i <= $r.rating}★{else}☆{/if}{/for}</span> · {$r.date_add} · <span class="pill ok">Doğrulanmış satın alma</span> · <span class="pill {if $r.status == 'approved'}ok{elseif $r.status == 'rejected'}bad{else}warn{/if}">{if $r.status == 'approved'}yayında{elseif $r.status == 'rejected'}reddedildi{else}onay bekliyor{/if}</span></p>
		<p class="rv-qa__q">{$r.comment}</p>
		{if $r.vendor_reply}<p class="rv-qa__a">↳ Yanıtınız: {$r.vendor_reply}</p>{/if}
		{if $canReply}<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="kind" value="store"><input type="hidden" name="id" value="{$r.id_store_review}">
			<label for="rep_s{$r.id_store_review}">Herkese açık yanıt</label><textarea id="rep_s{$r.id_store_review}" name="reply" rows="2" maxlength="1500" required></textarea>
			<p><button class="rv-btn" type="submit">Yanıtla</button></p></form>{/if}
	</div>
	{/foreach}
	{else}<p style="color:var(--rv-muted)">Henüz mağaza değerlendirmesi yok.</p>{/if}
</div>
