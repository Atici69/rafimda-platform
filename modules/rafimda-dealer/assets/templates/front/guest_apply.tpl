{include file="file:{$rdTplDir}guest_head.tpl"}
{function name=fld key='' label='' type='text' req=false}
	<label for="f_{$key}">{$label}{if $req} *{/if}</label>
	<input type="{$type}" id="f_{$key}" name="{$key}" value="{$form[$key]|default:''}"{if $req} required{/if}>
	{if $errors[$key]|default:''}<div class="err">{$errors[$key]}</div>{/if}
{/function}
<body class="rd"><div class="rd-guest wide"><div class="rd-card">
	<h1>Rafımda <span class="tag">Bayi Başvurusu</span></h1>
	{if $flash}<div class="rd-alert {$flash.type}">{$flash.message}</div>{/if}
	{if $done && $notice}
		<div class="rd-alert success">{$notice}</div>
		<p><a class="rd-btn" href="{$loginUrl}">Bayi Girişi</a></p>
	{else}
	<p style="color:var(--rd-muted)">Rafımda ailesine katılın, kendi bayi ağınızı oluşturun ve birlikte büyüyelim. {if $loggedIn}Mevcut hesabınızla başvurun.{else}Hesabınız varsa e-postanızı ve mevcut şifrenizi girin; yoksa yeni hesap oluşturulur.{/if} Başvurunuz yönetici tarafından değerlendirilir.</p>
	<form method="post" action="">
		<input type="hidden" name="token" value="{$rdToken}">
		{if !$loggedIn}
		<h3>Hesap bilgileri</h3>
		<div class="rd-row2">
			<div>{call fld key='account_email' label='Hesap e-postası' type='email' req=true}</div>
			<div>
				<label for="f_password">Şifre *</label><input type="password" id="f_password" name="password" required autocomplete="new-password">
				{if $errors.password|default:''}<div class="err">{$errors.password}</div>{/if}
			</div>
		</div>
		<div class="rd-row3">
			<div>{call fld key='full_name' label='Ad soyad (yeni hesap)'}</div>
			<div>{call fld key='account_phone' label='Telefon (yeni hesap)'}</div>
			<div><label for="f_password2">Şifre tekrar (yeni hesap)</label><input type="password" id="f_password2" name="password2" autocomplete="new-password"></div>
		</div>
		{/if}
		<h3>Bayi bilgileri</h3>
		<div class="rd-row2">
			<div>{call fld key='company_name' label='Firma / bayi adı' req=true}</div>
			<div>{call fld key='contact_name' label='Yetkili adı soyadı' req=true}</div>
		</div>
		<div class="rd-row3">
			<div>{call fld key='phone' label='Telefon' type='tel' req=true}</div>
			<div>{call fld key='email' label='İletişim e-postası' type='email' req=true}</div>
			<div>{call fld key='city' label='Şehir' req=true}</div>
		</div>
		<div class="rd-row2">
			<div>{call fld key='tax_office' label='Vergi dairesi'}</div>
			<div>{call fld key='tax_number' label='Vergi / TC kimlik no'}</div>
		</div>
		<label for="f_note">Kendinizi tanıtın (isteğe bağlı)</label>
		<textarea id="f_note" name="note" rows="3">{$form.note|default:''}</textarea>
		<label for="f_parent_code">Üst bayi kodu (alt bayi olarak katılıyorsanız)</label>
		<input type="text" id="f_parent_code" name="parent_code" value="{$parentCode}" maxlength="16" autocomplete="off">
		<p><label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="accept_terms" value="1" style="width:auto;margin-top:4px" required> Bayilik koşullarını okudum ve kabul ediyorum.</label>
		{if $errors.accept_terms|default:''}<span class="err">{$errors.accept_terms}</span>{/if}</p>
		<p><button class="rd-btn" type="submit">BAYİ BAŞVURUSU YAP</button></p>
	</form>
	<p>Zaten bayi misiniz? <a href="{$loginUrl}">Giriş yapın</a></p>
	{/if}
</div></div></body></html>
