{include file="file:{$rvTplDir}guest_head.tpl"}
<body class="rv"><div class="rv-guest"><div class="rv-card">
	<a class="rv-guest-logo" href="{$rvDomain|default:'/'}"><img src="{$rvLogoUrl|default:'/img/logo.png'}" alt="Rafımda"></a>
	<h1><span class="tag">Satıcı</span> Girişi</h1>
	<p style="color:var(--rv-muted)">Aradığınız Her Şey, Rafımda! Mevcut hesabınızla giriş yapın.</p>
	{if $error}<div class="rv-alert danger">{$error}</div>{/if}
	<form method="post" action="">
		<input type="hidden" name="token" value="{$rvToken}">
		<label for="login">E-posta veya telefon</label>
		<input type="text" id="login" name="login" value="{$login}" autocomplete="username" required>
		<label for="password">Şifre</label>
		<input type="password" id="password" name="password" autocomplete="current-password" required>
		<p><button class="rv-btn" type="submit" style="width:100%">Giriş Yap</button></p>
	</form>
	<p>Henüz satıcı değil misiniz? <a href="{$registerUrl}">Satıcı olun</a></p>
</div></div></body></html>
