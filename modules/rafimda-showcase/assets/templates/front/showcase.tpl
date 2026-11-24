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
{if $rsRecent}
<section class="rs-block rs-recent"><h2 class="rs-title">Son Gezdiğiniz Ürünler</h2>
	<div class="rs-grid">
	{foreach $rsRecent as $i}
		<a class="rs-card" href="{$i.url|escape}">{if $i.image}<img src="{$i.image|escape}" alt="{$i.title|escape}" loading="lazy">{else}<span class="rs-noimg"></span>{/if}
			<span class="rs-name">{$i.title|escape}</span>
			<span class="rs-price">{$i.price|string_format:"%.2f"} ₺{if $i.old_price} <s>{$i.old_price|string_format:"%.2f"} ₺</s>{/if}</span></a>
	{/foreach}
	</div>
</section>
{/if}
{if $rsStores}
<section class="rs-block rs-stores"><h2 class="rs-title">Öne Çıkan Mağazalar</h2>
	<div class="rs-store-grid">
	{foreach $rsStores as $st}
		<a class="rs-store" href="{$st.url|escape}">
			{if $st.logo}<img src="{$st.logo|escape}" alt="{$st.name|escape} logosu" width="56" height="56" loading="lazy">{else}<span class="rs-store-ph" aria-hidden="true">{$st.initial|escape}</span>{/if}
			<span class="rs-store-name">{$st.name|escape}</span>
			<span class="rs-store-meta">{if $st.rating_count > 0}★ {$st.rating|string_format:"%.1f"} ({$st.rating_count}){else}Henüz değerlendirme yok{/if} · {$st.products} ürün{if $st.city} · {$st.city|escape}{/if}</span>
		</a>
	{/foreach}
	</div>
</section>
{/if}
</div>
