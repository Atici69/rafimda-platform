<aside class="rvs-seller" aria-label="Satıcı bilgisi">
	<div class="rvs-seller__head">
		{if $svProfile.logo_url}<img src="{$svProfile.logo_url|escape}" alt="" width="44" height="44">{else}<span class="rvs-seller__ph">{$sv.store_name|truncate:1:''|escape}</span>{/if}
		<div><span class="rvs-seller__lbl">Satıcı</span><a class="rvs-seller__name" href="{$svUrl|escape}">{$sv.store_name|escape}</a>
		<span class="rvs-seller__meta">{if $svRating.count}★ {$svRating.avg} ({$svRating.count} değerlendirme){else}Henüz değerlendirme yok{/if} · {$svFollowers} takipçi</span></div>
	</div>
	{if $svProfile.shipping_note}<p class="rvs-seller__note"><strong>Kargo:</strong> {$svProfile.shipping_note|escape}</p>{/if}
	{if $svProfile.return_note}<p class="rvs-seller__note"><strong>İade:</strong> {$svProfile.return_note|escape}</p>{/if}
	<p class="rvs-seller__note">Bu ürünün satıcısı {$sv.store_name|escape} mağazasıdır; Rafımda aracı hizmet sağlayıcıdır.</p>
	<a class="rvs-btn rvs-btn--ghost" href="{$svUrl|escape}">Mağazaya git</a>
</aside>
