<!DOCTYPE html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>{$rdTitle} · Rafımda Bayi</title>
<link rel="stylesheet" href="{$rdCss}"></head>
<body class="rd"><div class="rd-shell">
<aside class="rd-side" id="rd-side">
	<div class="rd-brand">Rafımda <span>{$levelLabel}</span><small>{$dealer.company_name}</small></div>
	<ul class="rd-nav">
	{foreach $menu as $m}
		<li><a href="{$m.url}" class="{if $m.key == $section}is-active{/if}"><span class="ico">{$m.icon}</span>{$m.label}</a></li>
	{/foreach}
	</ul>
</aside>
<div class="rd-scrim"></div>
<div class="rd-main">
	<header class="rd-top">
		<button class="rd-burger" type="button" aria-label="Menü">☰</button>
		<strong>{$pageTitle}</strong><span class="grow"></span>
		<span style="color:var(--rd-muted);font-size:13px">{$levelLabel}</span>
		<a href="{$storefrontUrl}" target="_blank" rel="noopener" class="rd-btn ghost sm">Mağazayı gör</a>
		<form method="post" action="{$logoutUrl}" style="margin:0"><input type="hidden" name="token" value="{$rdToken}"><button class="rd-btn navy sm" type="submit">Çıkış</button></form>
	</header>
	<main class="rd-content">
		{if $flash}<div class="rd-alert {$flash.type}">{$flash.message}</div>{/if}
		{include file=$contentFile}
	</main>
</div></div>
<script src="{$rdJs}"></script></body></html>
