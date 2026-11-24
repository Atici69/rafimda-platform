<main class="rvs-page">
	<header class="rvs-hero" style="{if $svProfile.cover_url}background-image:linear-gradient(0deg,rgba(11,37,69,.75),rgba(11,37,69,.25)),url('{$svProfile.cover_url|escape}'){/if}">
		<div class="rvs-hero__in container">
			{if $svProfile.logo_url}<img class="rvs-logo" src="{$svProfile.logo_url|escape}" alt="{$sv.store_name|escape} logosu" width="96" height="96">{else}<span class="rvs-logo rvs-logo--ph" aria-hidden="true">{$sv.store_name|truncate:1:''|escape}</span>{/if}
			<div class="rvs-hero__txt">
				<h1>{$sv.store_name|escape}</h1>
				<p class="rvs-hero__meta">{if $svRating.count}<span class="rvs-star">★ {$svRating.avg}</span> · {$svRating.count} değerlendirme{else}Henüz değerlendirme yok{/if} · {$svFollowers} takipçi{if $sv.city} · {$sv.city|escape}{/if}</p>
			</div>
			<div class="rvs-hero__act">
				{if !$svIsStaff}
				<form method="post" action="{$svActionUrl|escape}"><input type="hidden" name="token" value="{$svToken|escape}"><input type="hidden" name="id_vendor" value="{$sv.id_vendor}">
					<input type="hidden" name="action" value="{if $svFollowing}unfollow{else}follow{/if}">
					<button class="rvs-btn{if $svFollowing} rvs-btn--ghost{/if}" type="submit">{if $svFollowing}✓ Takip ediliyor{else}+ Mağazayı takip et{/if}</button>
				</form>
				{/if}
			</div>
		</div>
	</header>
	<div class="container">
		{if $svFlash}<div class="rvs-flash rvs-flash--{$svFlash.type|escape}" role="status">{$svFlash.message|escape}</div>{/if}
		<nav class="rvs-tabs" aria-label="Mağaza bölümleri">
			{foreach ['new' => 'Yeni ürünler', 'best' => 'Çok satanlar', 'reviews' => 'Değerlendirmeler', 'questions' => 'Soru-Cevap', 'about' => 'Hakkında'] as $k => $l}<a class="{if $svTab == $k}is-on{/if}" href="{$svUrl|escape}&tab={$k}"{if $svTab == $k} aria-current="page"{/if}>{$l}</a>{/foreach}
		</nav>
		{if $svTab == 'new' || $svTab == 'best'}
			{if $svProducts}
			<div class="rvs-grid">
			{foreach $svProducts as $p}
				<a class="rvs-card" href="{$p.url|escape}">
					<span class="rvs-card__img">{if $p.image_url}<img src="{$p.image_url|escape}" alt="{$p.product_name|escape}" loading="lazy">{/if}{if $p.has_discount}<i>İndirim</i>{/if}</span>
					<span class="rvs-card__n">{$p.product_name|escape}</span>
					<span class="rvs-card__p">{if $p.has_discount}<s>{$p.old_price_formatted|escape}</s> {/if}<b>{$p.price_formatted|escape}</b></span>
				</a>
			{/foreach}
			</div>
			{else}<p class="rvs-empty">Bu mağazada henüz yayında ürün yok.</p>{/if}
		{elseif $svTab == 'reviews'}
			{foreach $svReviews as $r}
			<article class="rvs-rev">
				<p class="rvs-rev__meta"><span class="rvs-star" aria-label="{$r.rating} / 5">{for $i=1 to 5}{if $i <= $r.rating}★{else}☆{/if}{/for}</span> {$r.author|escape} · {$r.date_add|date_format:'%d.%m.%Y'} · <span class="rvs-badge">Doğrulanmış satın alma</span></p>
				<p>{$r.comment|escape}</p>
				{if $r.vendor_reply}<p class="rvs-reply"><strong>Satıcı yanıtı:</strong> {$r.vendor_reply|escape}</p>{/if}
				<form method="post" action="{$svActionUrl|escape}" class="rvs-inline"><input type="hidden" name="token" value="{$svToken|escape}"><input type="hidden" name="id_vendor" value="{$sv.id_vendor}"><input type="hidden" name="action" value="helpful"><input type="hidden" name="kind" value="store"><input type="hidden" name="id_review" value="{$r.id_store_review}">
					<button class="rvs-link" type="submit">👍 Faydalı buldum{if $r.helpful} ({$r.helpful}){/if}</button></form>
			</article>
			{foreachelse}<p class="rvs-empty">Henüz onaylı mağaza değerlendirmesi yok. Değerlendirmeler yalnızca teslim edilmiş siparişlerden yapılabilir.</p>{/foreach}
		{elseif $svTab == 'questions'}
			{foreach $svQuestions as $q}
			<article class="rvs-rev"><p class="rvs-rev__meta">{$q.author|escape} · {$q.date_add|date_format:'%d.%m.%Y'}</p><p><strong>Soru:</strong> {$q.question|escape}</p><p class="rvs-reply"><strong>{$sv.store_name|escape}:</strong> {$q.answer|escape}</p></article>
			{foreachelse}<p class="rvs-empty">Henüz yanıtlanmış soru yok.</p>{/foreach}
			{if $svLoggedIn && !$svIsStaff}
			<form method="post" action="{$svActionUrl|escape}" class="rvs-ask"><input type="hidden" name="token" value="{$svToken|escape}"><input type="hidden" name="id_vendor" value="{$sv.id_vendor}"><input type="hidden" name="action" value="ask">
				<label for="rvs-q">Mağazaya soru sorun</label><textarea id="rvs-q" name="question" rows="3" minlength="10" maxlength="1000" required placeholder="Telefon, e-posta gibi kişisel bilgi paylaşmayın."></textarea>
				<button class="rvs-btn" type="submit">Soruyu gönder</button></form>
			{elseif !$svLoggedIn}<p><a href="{$svLoginUrl|escape}">Giriş yapın</a> ve mağazaya soru sorun.</p>{/if}
		{else}
			<section class="rvs-about">
				{if $svProfile.about}<p>{$svProfile.about|escape|nl2br nofilter}</p>{elseif $sv.description}<p>{$sv.description|escape}</p>{else}<p class="rvs-empty">Mağaza henüz tanıtım metni eklemedi.</p>{/if}
				{if $svProfile.shipping_note}<p><strong>Kargo:</strong> {$svProfile.shipping_note|escape}</p>{/if}
				{if $svProfile.return_note}<p><strong>İade:</strong> {$svProfile.return_note|escape}</p>{/if}
				<p class="rvs-small">Satıcı: {$sv.legal_name|default:$sv.store_name|escape}. Rafımda bu mağaza için aracı hizmet sağlayıcıdır.</p>
			</section>
		{/if}
	</div>
</main>
