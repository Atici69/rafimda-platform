{include file="file:{$rdTplDir}guest_head.tpl"}
<body class="rd"><div class="rd-guest"><div class="rd-card">
	<h1>Rafımda <span class="tag">Bayi</span> Girişi</h1>
	<p style="color:var(--rd-muted)">Bayi kontrol panelinize güvenle giriş yapın. Aradığınız Her Şey, Rafımda!</p>
	{if $flash}<div class="rd-alert {$flash.type}">{$flash.message}</div>{/if}
	{if $error}<div class="rd-alert danger" role="alert">{$error}</div>{/if}
	<form method="post" action="">
		<input type="hidden" name="token" value="{$rdToken}">
		<label for="login">E-posta veya telefon</label>
		<input type="text" id="login" name="login" value="{$login}" autocomplete="username" required>
		<label for="password">Şifre</label>
		<input type="password" id="password" name="password" autocomplete="current-password" required>
		<p><button class="rd-btn" type="submit" style="width:100%">Giriş Yap</button></p>
	</form>
	<p><a href="{$forgotUrl}">Şifremi unuttum</a></p>
	<hr style="border:0;border-top:1px solid var(--rd-line);margin:16px 0">
	<p>Henüz bayimiz değil misiniz? <a href="{$applyUrl}"><strong>Bayi başvurusu yapın</strong></a></p>
	<p style="font-size:13px;color:var(--rd-muted)">Müşteri girişi için <a href="{$customerUrl}">buraya</a> tıklayın.</p>
</div></div></body></html>
