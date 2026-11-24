{if $rlLinks}
<nav class="rl-footlinks" aria-label="Hukuki bağlantılar">
	<a href="{$rlListUrl|escape}">Hukuki Belgeler</a>
	{foreach $rlLinks as $slug => $url}<a href="{$url|escape}">{if $slug == 'kvkk-aydinlatma'}KVKK Aydınlatma{elseif $slug == 'gizlilik-politikasi'}Gizlilik{elseif $slug == 'cerez-politikasi'}Çerez Politikası{elseif $slug == 'kullanim-kosullari'}Kullanım Koşulları{elseif $slug == 'iptal-iade-politikasi'}İptal ve İade{elseif $slug == 'teslimat-kargo'}Teslimat ve Kargo{elseif $slug == 'sikayet-uyusmazlik'}Şikâyet{elseif $slug == 'kvkk-basvuru'}KVKK Başvurusu{/if}</a>{/foreach}
	<a href="{$rlCookieUrl|escape}">Çerez Tercihleri</a>
</nav>
{/if}
{if !$rlPrefs.set}
<div class="rl-banner" id="rl-banner" role="dialog" aria-live="polite" aria-label="Çerez bildirimi">
	<form method="post" action="{$rlCookieUrl|escape}" class="rl-banner__in">
		<p>Sitemiz zorunlu çerezler kullanır. Analitik ve pazarlama çerezleri <strong>yalnızca izninizle</strong> çalışır. <a href="{$rlCookieUrl|escape}">Ayrıntılar ve tercihler</a></p>
		<input type="hidden" name="token" value="{$rlToken|escape}" data-rl-token>
		<input type="hidden" name="back" value="" data-rl-back>
		<button class="rl-btn rl-btn--ghost" type="submit" name="choice" value="none">Yalnızca zorunlu</button>
		<a class="rl-btn rl-btn--ghost" href="{$rlCookieUrl|escape}">Tercihleri yönet</a>
		<button class="rl-btn" type="submit" name="choice" value="all">Tümünü kabul et</button>
	</form>
</div>
{/if}
