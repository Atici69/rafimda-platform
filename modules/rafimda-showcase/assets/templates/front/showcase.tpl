<div class="rs-showcase">
{foreach $rsBlocks as $b}
<section class="rs-block rs-{$b.code|escape}"><h2 class="rs-title">{$b.title|escape}</h2>
	<div class="rs-grid">
	{foreach $b.items as $i}
		{if $b.kind == 'banner'}
		<a class="rs-banner" href="{$i.url|escape}" rel="sponsored noopener"><img src="{$i.image|escape}" alt="{$i.title|escape}" loading="lazy"><span>{$i.title|escape}</span></a>
		{else}
		<a class="rs-card" href="{$i.url|escape}">{if $i.image}<img src="{$i.image|escape}" alt="{$i.title|escape}" loading="lazy">{else}<span class="rs-noimg"></span>{/if}
			<span class="rs-name">{$i.title|escape}</span>
			<span class="rs-price">{$i.price|string_format:"%.2f"} ₺{if $i.old_price} <s>{$i.old_price|string_format:"%.2f"} ₺</s>{/if}</span></a>
		{/if}
	{/foreach}
	</div>
</section>
{/foreach}
</div>
