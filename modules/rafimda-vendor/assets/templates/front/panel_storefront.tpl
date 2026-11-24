<div class="rv-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
<div class="rv-card">
	<h2>Mağaza vitrini</h2>
	<p style="color:var(--rv-muted)">Müşterilerin gördüğü sayfa: <a href="{$publicUrl}" target="_blank" rel="noopener">{$publicUrl}</a></p>
	<p>Puan: {if $rating.count}<strong>{$rating.avg}</strong> / 5 ({$rating.count} onaylı değerlendirme){else}henüz onaylı değerlendirme yok{/if} · Takipçi: <strong>{$followers}</strong></p>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="profile">
		<label for="about">Hakkımızda</label>
		<textarea id="about" name="about" rows="5" maxlength="3000"{if !$can.store} disabled{/if}>{$profile.about}</textarea>
		<label for="shipping_note">Kargo notu</label>
		<input id="shipping_note" name="shipping_note" maxlength="255" value="{$profile.shipping_note}" placeholder="Örn. Hafta içi 14:00'e kadar verilen siparişler aynı gün kargoda"{if !$can.store} disabled{/if}>
		<label for="return_note">İade notu</label>
		<input id="return_note" name="return_note" maxlength="255" value="{$profile.return_note}"{if !$can.store} disabled{/if}>
		{if $can.store}<p><button class="rv-btn" type="submit">Kaydet</button></p>{/if}
	</form>
</div>
<div class="rv-card">
	<h2>Logo ve kapak</h2>
	<div class="rv-sf-prev" style="background:{if $profile.cover_url}url('{$profile.cover_url}') center/cover{else}linear-gradient(120deg,var(--rv-navy),var(--rv-navy-2)){/if}">
		{if $profile.logo_url}<img src="{$profile.logo_url}" alt="Logo" width="72" height="72">{else}<span class="rv-sf-ph">{$vendor.store_name|truncate:1:''}</span>{/if}
	</div>
	{if $can.store}
	{foreach ['logo' => 'Logo (kare, en fazla 400 px)', 'cover' => 'Kapak (en fazla 1600×500 px)'] as $k => $l}
	<form method="post" enctype="multipart/form-data" class="rv-upload"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="{$k}">
		<label for="img_{$k}">{$l} · JPG/PNG/WEBP · en fazla 3 MB</label>
		<input type="file" id="img_{$k}" name="image" accept=".jpg,.jpeg,.png,.webp" required>
		<p><button class="rv-btn navy" type="submit">Yükle</button></p>
	</form>
	{/foreach}
	{else}<p style="color:var(--rv-muted)">Vitrini yalnızca mağaza sahibi düzenleyebilir.</p>{/if}
	<p style="color:var(--rv-muted);font-size:13px">Görseller güvenlik için yeniden işlenir (gömülü veri ve konum bilgisi silinir).</p>
</div>
</div>
