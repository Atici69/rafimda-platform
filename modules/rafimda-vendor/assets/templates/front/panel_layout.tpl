<!DOCTYPE html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>{$rvTitle} · Rafımda Satıcı</title>
<link rel="stylesheet" href="{$rvCss}"></head>
<body class="rv"><div class="rv-shell">
<aside class="rv-side" id="rv-side">
	<div class="rv-brand">Rafımda <span>Satıcı</span><small>{$vendor.store_name}</small></div>
	<ul class="rv-nav">
	{foreach $menu as $m}
		<li><a href="{$m.url}" class="{if $m.key == $section}is-active{/if}"><span class="ico">{$m.icon}</span>{$m.label}{if !$m.ready}<span class="soon">yakında</span>{/if}</a></li>
	{/foreach}
	</ul>
</aside>
<div class="rv-scrim"></div>
<div class="rv-main">
	<header class="rv-top">
		<button class="rv-burger" type="button" aria-label="Menü">☰</button>
		<strong>{$pageTitle}</strong><span class="grow"></span>
		{if $memberships|count > 1}
		<form method="post" action="{$rvDomain}satici-panel"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="switch_vendor">
			<select name="id_vendor" onchange="this.form.submit()" style="width:auto">
			{foreach $memberships as $mb}<option value="{$mb.id_vendor}"{if $mb.id_vendor == $vendor.id_vendor} selected{/if}>{$mb.store_name}</option>{/foreach}
			</select></form>
		{/if}
		<span style="color:var(--rv-muted);font-size:13px">{$roleLabel}</span>
		<a href="{$storefrontUrl}" target="_blank" rel="noopener" class="rv-btn ghost sm">Mağazayı gör</a>
		<form method="post" action="{$logoutUrl}" style="margin:0"><input type="hidden" name="token" value="{$rvToken}"><button class="rv-btn navy sm" type="submit">Çıkış</button></form>
	</header>
	<main class="rv-content">
		{if $flash}<div class="rv-alert {$flash.type}">{$flash.message}</div>{/if}
		{if $denied|default:false}
			<div class="rv-card"><h2>Erişim yok</h2><p>Bu bölüm için yetkiniz bulunmuyor.</p></div>
		{else}
			{include file=$contentFile}
		{/if}
		{if $rafiHelp|default:''}{$rafiHelp nofilter}{/if}
	</main>
</div></div>
<script src="{$rvJs}"></script></body></html>
