{* Rafımda Pazaryeri ana sayfası. Veriler yalnızca veritabanından (container/front/home.php); boş bölüm hiç çizilmez. *}
{* Not: {$hooks.home} bilerek kullanılmaz — Theme Edit (theme4) modülünün sayfa oluşturucusu yalnızca theme4 içindir. *}
<div class="rf-home sm-container">

	{if $homeCategories|default:[]|@count > 0}
	<nav class="rf-stories" aria-label="Kategoriler">
		{if isset($dealProducts) && $dealProducts|@count > 0}
		<a href="{$domain}special" class="rf-story rf-story--hot"><span class="rf-story__ring"><span class="rf-story__img rf-story__img--text">%</span></span><span class="rf-story__label">Fırsat Ürünleri</span></a>
		{/if}
		{foreach $homeCategories as $hc}
		<a href="{$hc.url|escape}" class="rf-story">
			<span class="rf-story__ring"><span class="rf-story__img">{if $hc.category.image_url|default:'' != ''}<img src="{$hc.category.image_url|escape}" alt="" loading="lazy">{else}<span class="rf-story__initial">{$hc.initial|escape}</span>{/if}</span></span>
			<span class="rf-story__label">{$hc.category.category_name|escape}</span>
		</a>
		{/foreach}
	</nav>
	{/if}

	<section class="rf-hero{if !$hooks.home_slider} rf-hero--solo{/if}">
		<div class="rf-hero__main">
			{if $hooks.home_slider}
				{$hooks.home_slider nofilter}
			{else}
				<div class="rf-hero__fallback">
					<span class="rf-hero__kicker">Çok satıcılı pazar yeri</span>
					<strong class="rf-hero__title">Rafında yoksa Rafımda var.</strong>
					<span class="rf-hero__text">Aradığınız Her Şey, Rafımda!</span>
					<a href="{$domain}special" class="rf-btn">Alışverişe Başla</a>
				</div>
			{/if}
		</div>
		{if $homeCategories|default:[]|@count >= 2}
		<div class="rf-hero__side">
			{foreach $homeCategories as $hc}{if $hc@iteration <= 2}
			<a href="{$hc.url|escape}" class="rf-promo rf-promo--{$hc@iteration}">
				<span class="rf-promo__kicker">{$hc.category.category_name|escape}</span>
				<strong class="rf-promo__title">Kategoriyi keşfedin</strong>
				<span class="rf-promo__more">Alışverişe başla ›</span>
				{if $hc.category.image_url|default:'' != ''}<img class="rf-promo__img" src="{$hc.category.image_url|escape}" alt="" loading="lazy">{/if}
			</a>
			{/if}{/foreach}
		</div>
		{/if}
	</section>

	<section class="rf-perks" aria-label="Alışveriş güvencesi">
		<div class="rf-perk"><span class="rf-perk__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span><span><strong>Güvenli ödeme</strong><small>Ödeme bilgileriniz korunur</small></span></div>
		<a class="rf-perk" href="{$domain}truck"><span class="rf-perk__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62L18.3 9.38a1 1 0 0 0-.78-.38H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg></span><span><strong>Kargo takibi</strong><small>Siparişiniz her adımda</small></span></a>
		<div class="rf-perk"><span class="rf-perk__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></span><span><strong>Kolay iade</strong><small>İade koşullarına göre</small></span></div>
		<div class="rf-perk"><span class="rf-perk__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9h18l-2 11H5L3 9Z"/><path d="M8 9V5a4 4 0 0 1 8 0v4"/></svg></span><span><strong>Çok satıcılı</strong><small>Fiyatları karşılaştırın</small></span></div>
	</section>

	<div id="rt-urunler"></div>
	{capture assign=rfCountdown}<span class="rf-countdown" data-rf-countdown aria-label="Günün bitmesine kalan süre"><span class="rf-countdown__label">Bitmesine</span><b data-h>00</b><i>:</i><b data-m>00</b><i>:</i><b data-s>00</b></span>{/capture}
	{include file='./partials/rf-section.tpl' rfTitle='Günün Fırsatları' rfProducts=$dealProducts|default:[] rfUrl=$domain|cat:'special' rfExtra=$rfCountdown}

	{if $homeCategories|default:[]|@count > 0}
	<section class="rf-section">
		<div class="rf-section__head"><h2 class="rf-section__title">Popüler Kategoriler</h2></div>
		<div class="rf-cats">
			{foreach $homeCategories as $hc}
			<a href="{$hc.url|escape}" class="rf-cat">
				<span class="rf-cat__img">{if $hc.category.image_url|default:'' != ''}<img src="{$hc.category.image_url|escape}" alt="" loading="lazy">{else}<span class="rf-cat__initial">{$hc.initial|escape}</span>{/if}</span>
				<span class="rf-cat__name">{$hc.category.category_name|escape}</span>
			</a>
			{/foreach}
		</div>
	</section>
	{/if}

	{include file='./partials/rf-section.tpl' rfTitle='Yeni Ürünler' rfProducts=$newProducts|default:[] rfUrl=$domain|cat:'special'}
	{include file='./partials/rf-section.tpl' rfTitle='En Beğenilenler' rfProducts=$topRatedProducts|default:[] rfUrl=''}

	{if isset($categoryBlocks)}
		{foreach $categoryBlocks as $block}
			{include file='./partials/rf-section.tpl' rfTitle=$block.category.category_name rfProducts=$block.products rfUrl=$block.url}
		{/foreach}
	{/if}

	{if $hooks.home_bottom}
	<div class="rf-home__bottom">{$hooks.home_bottom nofilter}</div>
	{/if}
</div>
<script>
(function () {
	var el = document.querySelector('[data-rf-countdown]');
	if (!el) { return; }
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function tick() {
		var now = new Date(), end = new Date(now); end.setHours(23, 59, 59, 999);
		var s = Math.max(0, Math.floor((end - now) / 1000));
		el.querySelector('[data-h]').textContent = pad(Math.floor(s / 3600));
		el.querySelector('[data-m]').textContent = pad(Math.floor(s % 3600 / 60));
		el.querySelector('[data-s]').textContent = pad(s % 60);
	}
	tick(); setInterval(tick, 1000);
})();
</script>
