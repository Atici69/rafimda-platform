{if $rtBanners}
<section class="rt-banners" aria-label="Kampanyalar">
	{foreach $rtBanners as $b}
	{if $b.link}<a class="rt-banner" href="{$b.link|escape}"{if $b.image} style="background-image:url('{$b.image|escape}')"{/if}>{else}<div class="rt-banner"{if $b.image} style="background-image:url('{$b.image|escape}')"{/if}>{/if}
		<span class="rt-banner__t">{$b.title|escape}</span>
		{if $b.text}<span class="rt-banner__x">{$b.text|escape}</span>{/if}
		{if $b.link && $b.cta}<span class="rt-banner__c">{$b.cta|escape}</span>{/if}
	{if $b.link}</a>{else}</div>{/if}
	{/foreach}
</section>
{/if}
{foreach $rtSections as $s}
<section class="rt-sec"{if $s@first} id="rt-urunler"{/if} data-section="{$s.key|escape}">
	<h2 class="rt-sec__h">{$s.title|escape}</h2>
	<div class="rt-grid">
	{foreach $s.products as $p}
		<a class="rt-card" href="{$p.url|escape}">
			<span class="rt-card__img">{if $p.image_url}<img src="{$p.image_url|escape}" alt="{$p.product_name|escape}" loading="lazy">{/if}{if $p.has_discount}<i class="rt-card__tag">İndirim</i>{/if}</span>
			<span class="rt-card__n">{$p.product_name|escape}</span>
			<span class="rt-card__p">{if $p.has_discount}<s>{$p.old_price_formatted|escape}</s> {/if}<b>{$p.price_formatted|escape}</b></span>
		</a>
	{/foreach}
	</div>
</section>
{/foreach}
{if $rtVendor}
<section class="rt-promo rt-promo--seller">
	<div class="rt-promo__in">
		<h2>SATIŞLARINIZI BÜYÜTMEK İÇİN YENİ ADRESİNİZ RAFIMDA!</h2>
		<ul>
			<li>Rekabetçi ve düşük komisyon avantajı</li>
			<li>İlk ay ana sayfada ürün tanıtım fırsatı</li>
			<li>Satıcı ürünlerinin dönüşümlü olarak gösterimi</li>
			<li>Satıcı dostu yaklaşım</li>
		</ul>
		<a class="rt-btn rt-btn--orange" href="{$rtRegisterUrl|escape}">Satıcımız Olmak İster misiniz?</a>
	</div>
</section>
{/if}
{if $rtDealer}
<section class="rt-promo rt-promo--dealer">
	<div class="rt-promo__in">
		<h2>RAFIMDA BAYİSİ OLMAK İSTER MİSİNİZ?</h2>
		<p>Rafımda ailesine katılın, kendi bayi ağınızı oluşturun ve birlikte büyüyelim.</p>
		<a class="rt-btn rt-btn--orange" href="{$rtDealerApplyUrl|escape}">BAYİ BAŞVURUSU YAP</a>
		<a class="rt-btn rt-btn--ghost" href="{$rtDealerLoginUrl|escape}">Bayi Girişi</a>
	</div>
</section>
{/if}
