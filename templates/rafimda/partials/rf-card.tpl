{* Rafımda pazaryeri ürün kartı. Değişken: $p (Product::enrichList satırı) *}
<article class="rf-card">
	<a class="rf-card__img" href="{$p.url}" title="{$p.product_name|escape}">
		<img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 3 4'%3E%3C/svg%3E" data-src="{$p.image_url}" class="lazy" loading="lazy" decoding="async" alt="{$p.product_name|escape}">
		{if $p.has_discount}<span class="rf-card__badge">%{Tools::getDiscount($p.old_price, $p.price)} İndirim</span>{elseif $p.label}<span class="rf-card__badge rf-card__badge--soft">{$p.label|escape}</span>{/if}
		{if !$p.in_stock}<span class="rf-card__oos">{'Out of Stock'|translate}</span>{/if}
	</a>
	<button type="button" class="rf-card__fav like-button toggle-favorite" data-id="{$p.id_product}" aria-label="{'Favorites'|translate}">
		<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
	</button>
	<div class="rf-card__body">
		<a href="{$p.url}" class="rf-card__title" title="{$p.product_name|escape}">{if $p.brand_name|default:'' != ''}<strong>{$p.brand_name|escape}</strong> {/if}{$p.product_name|escape}</a>
		{if $p.review_count > 0}
		<div class="rf-card__rating" aria-label="{$p.rating|string_format:'%.1f'} / 5">
			<span class="rf-card__stars">{for $i=1 to 5}{if $i <= $p.rating|string_format:'%.0f'}★{else}<span class="rf-card__star-off">★</span>{/if}{/for}</span>
			<span class="rf-card__count">({$p.review_count})</span>
		</div>
		{/if}
		<div class="rf-card__prices">
			{if $p.has_discount}<span class="rf-card__old">{$p.old_price_formatted}</span>{/if}
			<span class="rf-card__price">{$p.price_formatted}</span>
		</div>
		{if $p.in_stock}
		<button type="button" class="rf-card__cart addtocart" data-id="{$p.id_product}">{'Add to Cart'|translate}</button>
		{/if}
	</div>
</article>
