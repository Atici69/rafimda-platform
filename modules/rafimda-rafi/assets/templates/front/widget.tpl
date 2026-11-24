<div class="rafi" id="rafi" data-api="{$rafiApi|escape}" data-greet="{if $rafiGreet}1{else}0{/if}">
	<div class="rafi-hello" id="rafiHello" hidden>
		<button type="button" class="rafi-hello-x" aria-label="Kapat">×</button>
		<p>{$rafiGreeting|escape}</p>
	</div>
	<button type="button" class="rafi-fab" id="rafiFab" aria-controls="rafiPanel" aria-expanded="false" aria-label="Rafi alışveriş asistanını aç">
		<img src="{$rafiAvatar|escape}" alt="" width="44" height="44"><span class="rafi-fab-label">Rafi'ye sor</span>
	</button>
	<section class="rafi-panel" id="rafiPanel" role="dialog" aria-modal="false" aria-labelledby="rafiTitle" hidden>
		<header class="rafi-head">
			<img src="{$rafiAvatar|escape}" alt="" width="36" height="36">
			<div><strong id="rafiTitle">Rafi</strong><small>Rafımda alışveriş asistanı</small></div>
			<button type="button" class="rafi-close" id="rafiClose" aria-label="Kapat">×</button>
		</header>
		<div class="rafi-log" id="rafiLog" aria-live="polite"></div>
		<div class="rafi-chips" id="rafiChips"></div>
		<form class="rafi-form" id="rafiForm" autocomplete="off">
			<label class="visually-hidden" for="rafiInput">Mesajınız</label>
			<input type="text" id="rafiInput" maxlength="300" placeholder="Ör. 1000 TL altı kulaklık" required>
			<button type="submit" aria-label="Gönder">➤</button>
		</form>
		<p class="rafi-note">Rafi yalnızca Rafımda kataloğundaki güncel ürün, fiyat, stok ve satıcı bilgilerini kullanır.</p>
	</section>
</div>
