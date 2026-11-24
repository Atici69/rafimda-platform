{include file="file:{$rvTplDir}guest_head.tpl"}
<body class="rv"><div class="rv-guest"><div class="rv-card">
	<h1>{$vendor.store_name}</h1>
	<p>Mağaza durumu: <span class="pill warn">{$statusLabel}</span></p>
	{if $vendor.status == 'pending'}<p>Başvurunuz inceleniyor. Onaylandığında bu sayfadan panele erişebileceksiniz.</p>{/if}
	{if $vendor.status == 'rejected'}<p>Başvurunuz reddedildi.{if $vendor.rejection_reason} Neden: {$vendor.rejection_reason}{/if}</p>{/if}
	{if $vendor.status == 'suspended'}<p>Mağazanız askıya alındı. Destek ile iletişime geçin.</p>{/if}
	<form method="post" action="{$logoutUrl}"><input type="hidden" name="token" value="{$rvToken}"><button class="rv-btn navy" type="submit">Çıkış yap</button></form>
</div></div></body></html>
