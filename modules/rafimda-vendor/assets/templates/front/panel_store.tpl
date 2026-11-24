<div class="rv-card" style="max-width:760px"><h3>Mağaza bilgileri</h3>
	<p>Durum: <span class="pill ok">Aktif</span> · Mağaza adresi: <code>/{$form.store_slug}</code> · Paket: {$form.plan_code}</p>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}">
		<div class="rv-row2">
			<div><label>Mağaza adı *</label><input type="text" name="store_name" value="{$form.store_name}" required>{if $errors.store_name|default:''}<div class="err">{$errors.store_name}</div>{/if}</div>
			<div><label>Şehir</label><input type="text" name="city" value="{$form.city}"></div>
			<div><label>Ticari unvan</label><input type="text" name="legal_name" value="{$form.legal_name}"></div>
			<div><label>Vergi dairesi</label><input type="text" name="tax_office" value="{$form.tax_office}"></div>
			<div><label>Vergi / TC kimlik no</label><input type="text" name="tax_number" value="{$form.tax_number}">{if $errors.tax_number|default:''}<div class="err">{$errors.tax_number}</div>{/if}</div>
			<div><label>Telefon</label><input type="text" name="phone" value="{$form.phone}"></div>
		</div>
		<label>E-posta</label><input type="email" name="email" value="{$form.email}">{if $errors.email|default:''}<div class="err">{$errors.email}</div>{/if}
		<label>Açıklama</label><textarea name="description" rows="4">{$form.description}</textarea>
		{if $can.store}<p><button class="rv-btn" type="submit">Kaydet</button></p>{else}<p style="color:var(--rv-muted)">Düzenleme yetkiniz yok.</p>{/if}
	</form>
</div>
