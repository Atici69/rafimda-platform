<link rel="stylesheet" href="{$mhCss|escape}">
<div class="xh">
{if $mhPending}
<div class="alert warning"><form method="post" class="row" style="margin:0"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="mhAction" value="migrate">
	<span>Bekleyen migration: <strong>{implode(', ', $mhPending)|escape}</strong>. Çalıştırmadan önce veritabanı yedeği alın.</span><button class="btn navy sm" type="submit">Çalıştır</button></form></div>
{else}
<div class="xh-card"><strong>Gönderim kuyruğu (cron):</strong> <code style="word-break:break-all">{$mhCronUrl|escape}</code>
	<form method="post" class="row"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="mhAction" value="queue_tick"><button class="btn navy sm" type="submit">Kuyruğu şimdi işle</button></form></div>
{include file="file:{$mhTplDir}mh_content.tpl"}
{/if}
</div>
