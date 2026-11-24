<main class="rl-page container">
	{if $rlItem}
	<article class="rl-doc">
		<p class="rl-back"><a href="{$rlListUrl|escape}">← Tüm hukuki belgeler</a></p>
		<h1>{$rlItem.doc.title|escape}</h1>
		<p class="rl-meta">Sürüm {$rlItem.version.version|escape}{if $rlItem.version.published_at} · Yürürlük: {$rlItem.version.published_at|date_format:'%d.%m.%Y'}{/if}</p>
		<div class="rl-body">{$rlItem.html nofilter}</div>
	</article>
	{else}
	<h1>Hukuki Belgeler</h1>
	{foreach $rlGroups as $g => $gl}
		{$has = false}{foreach $rlDocs as $d}{if $d.grp == $g}{$has = true}{/if}{/foreach}
		{if $has}
		<section class="rl-group"><h2>{$gl|escape}</h2><ul>
		{foreach $rlDocs as $d}{if $d.grp == $g}<li><a href="{$rlListUrl|escape}?belge={$d.slug|escape:'url'}">{$d.title|escape}</a> <small>sürüm {$d.version_label|escape}</small></li>{/if}{/foreach}
		</ul></section>
		{/if}
	{/foreach}
	<p><a class="rl-btn" href="{$rlCookieUrl|escape}">Çerez tercihlerini yönet</a></p>
	{/if}
</main>
