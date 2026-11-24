<link rel="stylesheet" href="{$xhCss|escape}">
<div class="xh">
{if $xhPending}
<div class="alert warning"><form method="post" class="row" style="margin:0"><input type="hidden" name="token" value="{$adminToken|escape}"><input type="hidden" name="xhAction" value="migrate">
	<span>Bekleyen migration: <strong>{implode(', ', $xhPending)|escape}</strong>. Çalıştırmadan önce veritabanı yedeği alın.</span><button class="btn navy sm" type="submit">Çalıştır</button></form></div>
{else}
<div class="xh-card"><strong>Zamanlanmış görev (cron):</strong>
	{if $xhCronUrl}<div class="muted">Otomatik senkronizasyon için sunucu cron'unun bu adresi dakikada bir çağırması gerekir (çağrılmazsa zamanlanmış güncellemeler ÇALIŞMAZ):</div><code style="word-break:break-all">{$xhCronUrl|escape}</code>{else}<div class="muted">Anahtar henüz yok; modülü yeniden kurun.</div>{/if}</div>
{include file="file:{$xhTplDir}xh_content.tpl"}
{/if}
</div>
<script src="{$xhJs|escape}"></script>
