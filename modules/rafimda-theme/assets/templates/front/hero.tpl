<section class="rt-hero rt-slider{if $rtHeroImage} rt-hero--img{/if}" data-rt-slider data-interval="{$rtSlideSeconds|default:6|escape}" aria-roledescription="carousel" aria-label="Rafımda" tabindex="0"{if $rtHeroImage} style="background-image:url('{$rtHeroImage|escape}')"{/if}>
	<div class="rt-slide is-active" role="group" aria-roledescription="slide" aria-label="1">
		<div class="rt-hero__inner">
			<h1 class="rt-hero__title">{$rtTitle|escape}</h1>
			<p class="rt-hero__sub">{$rtSubtitle|escape}</p>
			<div class="rt-hero__cta">
				<a class="rt-btn rt-btn--orange" href="{$rtBase|escape}#rt-urunler">Alışverişe Başla</a>
				{if $rtVendor}<a class="rt-btn rt-btn--ghost" href="{$rtRegisterUrl|escape}">Satıcı Ol</a>{/if}
				{if $rtDealer}<a class="rt-btn rt-btn--ghost" href="{$rtDealerApplyUrl|escape}">Bayi Ol</a>{/if}
			</div>
			<ul class="rt-hero__badges"><li>Güvenli ödeme</li><li>Çok satıcılı pazar yeri</li><li>Hızlı kargo takibi</li></ul>
		</div>
	</div>
	{foreach $rtSlides|default:[] as $sl}
	<div class="rt-slide{if $sl.image} rt-slide--img{/if}" role="group" aria-roledescription="slide" aria-label="{$sl@iteration+1}" aria-hidden="true"{if $sl.image} style="background-image:url('{$sl.image|escape}')"{/if}>
		<div class="rt-hero__inner">
			<h2 class="rt-hero__title">{$sl.title|escape}</h2>
			{if $sl.text}<p class="rt-hero__sub">{$sl.text|escape}</p>{/if}
			{if $sl.link && $sl.cta}<div class="rt-hero__cta"><a class="rt-btn rt-btn--orange" href="{$sl.link|escape}" tabindex="-1">{$sl.cta|escape}</a></div>{/if}
		</div>
	</div>
	{/foreach}
	{if $rtSlides|default:[]|count > 0}
	<button class="rt-nav rt-nav--prev" type="button" aria-label="Önceki slayt">‹</button>
	<button class="rt-nav rt-nav--next" type="button" aria-label="Sonraki slayt">›</button>
	<div class="rt-dots" role="tablist" aria-label="Slayt seçimi">
		<button class="rt-dot is-active" type="button" aria-label="Slayt 1"></button>
		{foreach $rtSlides as $sl}<button class="rt-dot" type="button" aria-label="Slayt {$sl@iteration+1}"></button>{/foreach}
	</div>
	{/if}
</section>
{if $rtCategories}
<nav class="rt-cats" aria-label="Kategoriler">
	{foreach $rtCategories as $c}
	<a class="rt-cat" href="{$c.url|escape}">
		{if $c.image}<img src="{$c.image|escape}" alt="" loading="lazy" width="72" height="72">{else}<span class="rt-cat__ph">{$c.initial|escape}</span>{/if}
		<span>{$c.name|escape}</span>
	</a>
	{/foreach}
</nav>
{/if}
