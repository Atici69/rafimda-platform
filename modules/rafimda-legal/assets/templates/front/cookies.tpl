<main class="rl-page container">
	<h1>Çerez Tercihleri</h1>
	{if $rlSaved}<div class="rl-ok" role="status">Tercihleriniz kaydedildi.</div>{/if}
	{if $rlError}<div class="rl-err" role="alert">{$rlError|escape}</div>{/if}
	{if $rlItem}<div class="rl-body">{$rlItem.html nofilter}</div>{/if}
	<form method="post" class="rl-cookie-form">
		<input type="hidden" name="token" value="{$rlToken|escape}">
		<label class="rl-switch is-locked"><input type="checkbox" checked disabled> <span><strong>Zorunlu çerezler</strong> — oturum, sepet ve güvenlik. Her zaman açık.</span></label>
		<label class="rl-switch"><input type="checkbox" name="analytics" value="1"{if $rlPrefs.analytics} checked{/if}> <span><strong>Analitik çerezler</strong> — ziyaret istatistikleri.</span></label>
		<label class="rl-switch"><input type="checkbox" name="marketing" value="1"{if $rlPrefs.marketing} checked{/if}> <span><strong>Pazarlama çerezleri</strong> — reklam ölçümü.</span></label>
		<div class="rl-actions">
			<button class="rl-btn" type="submit" name="choice" value="custom">Seçimimi kaydet</button>
			<button class="rl-btn rl-btn--ghost" type="submit" name="choice" value="none">Yalnızca zorunlu</button>
			<button class="rl-btn rl-btn--ghost" type="submit" name="choice" value="all">Tümünü kabul et</button>
		</div>
		<p class="rl-small"><a href="{$rlPolicyUrl|escape}">Çerez Politikası</a></p>
	</form>
</main>
