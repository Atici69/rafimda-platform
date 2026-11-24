{include file="file:{$rvTplDir}guest_head.tpl"}
{function name=fld key='' label='' type='text' req=false}
	<label for="f_{$key}">{$label}{if $req} *{/if}</label>
	<input type="{$type}" id="f_{$key}" name="{$key}" value="{$form[$key]|default:''}"{if $req} required{/if}>
	{if $errors[$key]|default:''}<div class="err">{$errors[$key]}</div>{/if}
{/function}
<body class="rv"><div class="rv-guest wide"><div class="rv-card">
	<h1>Rafımda'da <span class="tag">Satıcı Ol</span></h1>
	{if $done && $notice}
		<div class="rv-alert success">{$notice}</div>
		<p><a class="rv-btn" href="{$loginUrl}">Satıcı Girişi</a></p>
	{else}
	<p style="color:var(--rv-muted)">{if $loggedIn}Mevcut hesabınızla mağaza başvurusu yapın.{else}Hesabınız varsa e-postanızı ve mevcut şifrenizi girin; yoksa yeni hesap oluşturulur.{/if} Başvurunuz onaylandıktan sonra panel açılır.</p>
	<form method="post" action="">
		<input type="hidden" name="token" value="{$rvToken}">
		{if !$loggedIn}
		<h3>Hesap bilgileri</h3>
		<div class="rv-row2">
			<div>{call fld key='account_email' label='Hesap e-postası' type='email' req=true}</div>
			<div>
				<label for="f_password">Şifre *</label><input type="password" id="f_password" name="password" required autocomplete="new-password">
				{if $errors.password|default:''}<div class="err">{$errors.password}</div>{/if}
			</div>
		</div>
		<div class="rv-row3">
			<div>{call fld key='full_name' label='Ad soyad (yeni hesap)'}</div>
			<div>{call fld key='account_phone' label='Telefon (yeni hesap)'}</div>
			<div><label for="f_password2">Şifre tekrar (yeni hesap)</label><input type="password" id="f_password2" name="password2" autocomplete="new-password"></div>
		</div>
		{/if}
		<h3 style="margin-top:18px">Mağaza bilgileri</h3>
		<div class="rv-row2">
			<div>{call fld key='store_name' label='Mağaza adı' req=true}</div>
			<div>{call fld key='city' label='Şehir'}</div>
			<div>{call fld key='legal_name' label='Ticari unvan'}</div>
			<div>{call fld key='tax_office' label='Vergi dairesi'}</div>
			<div>{call fld key='tax_number' label='Vergi / TC kimlik no'}</div>
			<div>{call fld key='phone' label='Mağaza telefonu'}</div>
		</div>
		{call fld key='email' label='Mağaza e-postası' type='email'}
		<label for="f_description">Mağaza açıklaması</label>
		<textarea id="f_description" name="description" rows="3">{$form.description|default:''}</textarea>
		<label style="font-weight:400"><input type="checkbox" name="accept_terms" value="1"{if $form} checked{/if}> Satıcı sözleşmesini okudum ve kabul ediyorum.</label>
		{if $errors.accept_terms|default:''}<div class="err">{$errors.accept_terms}</div>{/if}
		<p><button class="rv-btn" type="submit">Başvuruyu Gönder</button> <a href="{$loginUrl}">Zaten satıcıyım</a></p>
	</form>
	{/if}
</div></div></body></html>
