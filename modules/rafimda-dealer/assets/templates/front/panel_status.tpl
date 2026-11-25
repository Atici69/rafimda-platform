{include file="file:{$rdTplDir}guest_head.tpl"}
<body class="rd"><div class="rd-guest"><div class="rd-card">
	<a class="rd-guest-logo" href="{$rdDomain|default:'/'}"><img src="{$rdLogoUrl|default:'/img/logo.png'}" alt="Rafımda"></a>
	<h1><span class="tag">Bayi</span></h1>
	<h3>{$dealer.company_name}</h3>
	<div class="rd-alert {if $dealer.status == 'pending'}warning{else}danger{/if}">Durum: <strong>{$statusLabel}</strong></div>
	{if $dealer.status == 'pending'}<p>Başvurunuz yönetici tarafından değerlendiriliyor. Onaylandığında bu sayfadan panele erişebileceksiniz.</p>{/if}
	{if $dealer.status == 'rejected' && $dealer.rejection_reason}<p>Gerekçe: {$dealer.rejection_reason}</p>{/if}
	<form method="post" action="{$logoutUrl}"><input type="hidden" name="token" value="{$rdToken}"><button class="rd-btn navy" type="submit">Çıkış</button></form>
</div></div></body></html>
