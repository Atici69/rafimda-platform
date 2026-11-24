<div class="rv-card"><h3>Personel</h3>
	<table class="rv-resp"><thead><tr><th>Ad</th><th>E-posta</th><th>Rol</th><th>Durum</th><th></th></tr></thead><tbody>
	{foreach $staff as $s}<tr>
		<td data-l="Ad">{$s.user_full_name}</td><td data-l="E-posta">{$s.email}</td>
		{if $can.staff && $s.role != 'owner' && $s.id_user != $myUserId}
		<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="update_staff"><input type="hidden" name="id_staff" value="{$s.id_staff}">
		<td data-l="Rol"><select name="role">{foreach $roles as $k => $l}<option value="{$k}"{if $k == $s.role} selected{/if}>{$l}</option>{/foreach}</select></td>
		<td data-l="Durum"><select name="status"><option value="active"{if $s.status == 'active'} selected{/if}>Aktif</option><option value="disabled"{if $s.status == 'disabled'} selected{/if}>Devre dışı</option></select></td>
		<td><button class="rv-btn sm" type="submit">Kaydet</button></td></form>
		{else}
		<td data-l="Rol">{$allRoles[$s.role]|default:$s.role}</td><td data-l="Durum">{if $s.status == 'active'}<span class="pill ok">Aktif</span>{else}<span class="pill off">Devre dışı</span>{/if}</td><td></td>
		{/if}
	</tr>{/foreach}
	</tbody></table>
</div>
{if $can.staff}
<div class="rv-card" style="max-width:620px"><h3>Personel ekle</h3>
	<p style="color:var(--rv-muted);font-size:13px">Kişinin mağaza sitesinde zaten bir hesabı olmalıdır; yeni hesap açılmaz.</p>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="add_staff">
		<label>Hesap e-postası</label><input type="email" name="email" required>
		<label>Rol</label><select name="role">{foreach $roles as $k => $l}<option value="{$k}">{$l}</option>{/foreach}</select>
		<p><button class="rv-btn" type="submit">Ekle</button></p>
	</form>
</div>
{/if}
