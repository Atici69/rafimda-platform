<div class="rv-card" style="max-width:640px">
	<h2>Ayarlar</h2>
	<p style="color:var(--rv-muted)">Bu tercihler yalnızca mağazanızın panelini etkiler.</p>
	<form method="post"><input type="hidden" name="token" value="{$rvToken}">
		<label for="low_stock">Düşük stok eşiği (adet)</label>
		<input type="number" id="low_stock" name="low_stock" min="0" max="1000" value="{$prefs.low_stock}"{if !$can.store} disabled{/if}>
		<small style="color:var(--rv-muted)">Stoku bu sayıya eşit veya altına inen ürünler Genel Bakış ve Stok ekranında "düşük stok" sayılır.</small>
		<label for="default_cargo">Varsayılan kargo firması</label>
		<input type="text" id="default_cargo" name="default_cargo" maxlength="60" value="{$prefs.default_cargo}" placeholder="Örn. Yurtiçi Kargo"{if !$can.store} disabled{/if}>
		<small style="color:var(--rv-muted)">Sipariş detayında kargo formuna otomatik yazılır.</small>
		{if $can.store}<p><button class="rv-btn" type="submit">Kaydet</button></p>{else}<p style="color:var(--rv-muted)">Ayarları yalnızca mağaza sahibi değiştirebilir.</p>{/if}
	</form>
	<hr style="border:0;border-top:1px solid var(--rv-line);margin:18px 0">
	<p>Mağaza bilgileri için <a href="?s=store">Mağaza Yönetimi</a>, ekip için <a href="?s=staff">Personel</a>, limitler ve kampanyalar için <a href="?s=subscription">Limitler ve Kampanyalar</a> sayfalarına bakın.</p>
</div>
