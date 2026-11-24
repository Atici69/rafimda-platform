<section class="rt-hero" aria-label="Rafımda">
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
