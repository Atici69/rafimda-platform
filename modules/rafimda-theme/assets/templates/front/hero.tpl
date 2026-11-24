<section class="rt-hero" aria-label="Rafımda">
	<div class="rt-hero__inner">
		<h1 class="rt-hero__title">{$rtTitle|escape}</h1>
		<p class="rt-hero__sub">{$rtSubtitle|escape}</p>
		<div class="rt-hero__cta">
			<a class="rt-btn rt-btn--orange" href="{$rtBase|escape}">Alışverişe Başla</a>
			{if $rtVendor}<a class="rt-btn rt-btn--ghost" href="{$rtRegisterUrl|escape}">Satıcı Ol</a>{/if}
		</div>
		<ul class="rt-hero__badges"><li>Güvenli ödeme</li><li>Çok satıcılı pazar yeri</li><li>Hızlı kargo takibi</li></ul>
	</div>
</section>
