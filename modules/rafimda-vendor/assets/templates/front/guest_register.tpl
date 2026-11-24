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
		<h3 style="margin-top:18px">Başvuru türü</h3>
		<div class="rv-choice" role="radiogroup" aria-label="Başvuru türü">
			{foreach $personTypes|default:[] as $k => $l}<label class="rv-chip"><input type="radio" name="person_type" value="{$k}"{if ($form.person_type|default:'') == $k} checked{/if}> {$l}</label>{/foreach}
		</div>
		{if $errors.person_type|default:''}<div class="err">{$errors.person_type}</div>{/if}
		<h3 style="margin-top:18px">Firma ve vergi bilgileri</h3>
		<div class="rv-row2">
			<div>{call fld key='legal_name' label='Ticari unvan (tüzel kişide zorunlu)'}</div>
			<div>{call fld key='contact_name' label='Yetkili kişi' req=true}</div>
			<div>{call fld key='tax_office' label='Vergi dairesi' req=true}</div>
			<div>{call fld key='tax_number' label='Vergi no (10 hane) / T.C. kimlik no (11 hane)' req=true}</div>
			<div>{call fld key='phone' label='İletişim telefonu' type='tel' req=true}</div>
			<div>{call fld key='email' label='İletişim e-postası' type='email' req=true}</div>
		</div>
		<h3 style="margin-top:18px">Adres</h3>
		<label for="f_address">Açık adres *</label>
		<textarea id="f_address" name="address" rows="2" required>{$form.address|default:''}</textarea>
		{if $errors.address|default:''}<div class="err">{$errors.address}</div>{/if}
		<div class="rv-row2">
			<div>{call fld key='district' label='İlçe'}</div>
			<div>{call fld key='city' label='İl' req=true}</div>
		</div>
		<h3 style="margin-top:18px">Mağaza bilgileri</h3>
		{call fld key='store_name' label='Mağaza adı' req=true}
		<label for="f_description">Mağaza açıklaması * <small>(en az 20 karakter; vitrinde gösterilir)</small></label>
		<textarea id="f_description" name="description" rows="3" required>{$form.description|default:''}</textarea>
		{if $errors.description|default:''}<div class="err">{$errors.description}</div>{/if}
		<fieldset class="rv-cats"><legend>Satış yapacağınız kategoriler * <small>(en fazla 10)</small></legend>
			{foreach $categoryOptions|default:[] as $c}<label class="rv-chip"><input type="checkbox" name="categories[]" value="{$c.id}"{if in_array($c.id, $form.categories|default:[])} checked{/if}> {$c.name}</label>{foreachelse}<p class="err">Henüz etkin kategori yok; yöneticiye bildirin.</p>{/foreach}
		</fieldset>
		{if $errors.categories|default:''}<div class="err">{$errors.categories}</div>{/if}
		<p class="rv-note">Vergi levhası, imza sirküleri gibi belgeleri başvurudan sonra satıcı panelindeki <strong>Başvuru durumu</strong> sayfasından yükleyebilirsiniz (PDF/JPG/PNG, en fazla 5 MB).</p>
		<h3 style="margin-top:18px">Sözleşme ve bilgilendirme</h3>
		<label style="font-weight:400"><input type="checkbox" name="kvkk_ack" value="1"{if $form.kvkk_ack|default:''} checked{/if}> {if $legalLinks['kvkk-aydinlatma']|default:''}<a href="{$legalLinks['kvkk-aydinlatma']}" target="_blank" rel="noopener">KVKK Aydınlatma Metni</a>{else}KVKK Aydınlatma Metni{/if}'ni okudum. *</label>
		{if $errors.kvkk_ack|default:''}<div class="err">{$errors.kvkk_ack}</div>{/if}
		<label style="font-weight:400"><input type="checkbox" name="accept_terms" value="1"{if $form.accept_terms|default:''} checked{/if}> {if $legalLinks['satici-sozlesmesi']|default:''}<a href="{$legalLinks['satici-sozlesmesi']}" target="_blank" rel="noopener">Satıcı Sözleşmesi</a>{else}Satıcı Sözleşmesi{/if}'ni okudum ve kabul ediyorum. *</label>
		{if $errors.accept_terms|default:''}<div class="err">{$errors.accept_terms}</div>{/if}
		<p><button class="rv-btn" type="submit">Başvuruyu Gönder</button> <a href="{$loginUrl}">Zaten satıcıyım</a></p>
	</form>
	{/if}
</div></div></body></html>
