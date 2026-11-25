<link rel="stylesheet" href="{$mhCss|escape}">
<div class="xh">
{if $mhPending}
<div class="alert warning"><form method="post" class="row" style="margin:0"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="mhAction" value="migrate">
	<span>Bekleyen migration: <strong>{implode(', ', $mhPending)|escape}</strong>. Çalıştırmadan önce veritabanı yedeği alın.</span><button class="btn navy sm" type="submit">Çalıştır</button></form></div>
{else}
<div class="xh-card"><strong>Gönderim kuyruğu (cron):</strong> <code style="word-break:break-all">{$mhCronUrl|escape}</code>
	<form method="post" class="row"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="mhAction" value="queue_tick"><button class="btn navy sm" type="submit">Kuyruğu şimdi işle</button></form></div>
<div class="xh-card"><strong>Canlı ürün gönderimi:</strong> {if $mhLive}<span class="pill">AÇIK</span> Kuyruktaki ürünler pazaryerine gerçekten gönderilir.{else}<span class="pill">KAPALI</span> Ürünler hazırlanır ve doğrulanır, pazaryerine gönderilmez.{/if}
	<form method="post" class="row" onsubmit="return confirm('{if $mhLive}Canlı gönderim kapatılsın mı?{else}Canlı gönderim açılsın mı? Satıcıların kuyruğa aldığı ürünler kendi pazaryeri hesaplarında gerçekten yayına gönderilecek.{/if}')"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="mhAction" value="live_toggle"><input type="hidden" name="live" value="{if $mhLive}0{else}1{/if}"><button class="btn {if $mhLive}ghost{else}danger{/if} sm" type="submit">{if $mhLive}Kapat{else}Canlı gönderimi aç{/if}</button></form></div>
{include file="file:{$mhTplDir}mh_content.tpl"}
{/if}
</div>
