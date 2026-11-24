{if $rafiFlash}<div class="alert alert-{$rafiFlash.type|escape}">{$rafiFlash.message|escape}</div>{/if}
<div class="d-flex align-items-center gap-3 mb-3"><img src="{$rafiAvatar|escape}" alt="Rafi" width="64" height="64"><div><h4 class="mb-0">Rafi Alışveriş Asistanı</h4><small class="text-muted">Kural tabanlı, ücretsiz; harici yapay zekâ API'si bağlı değildir. Yanıtlar yalnızca katalogdaki gerçek kayıtlardan üretilir.</small></div></div>
<div class="row g-3">
<div class="col-lg-5"><div class="card"><div class="card-body">
	<form method="post">
		<input type="hidden" name="token" value="{$rafiToken|escape}">
		<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="RAFI_ENABLED" name="RAFI_ENABLED" value="1"{if $rafiSettings.RAFI_ENABLED} checked{/if}><label class="form-check-label" for="RAFI_ENABLED">Rafi sohbet balonu açık</label></div>
		<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="RAFI_GREET_HOME" name="RAFI_GREET_HOME" value="1"{if $rafiSettings.RAFI_GREET_HOME} checked{/if}><label class="form-check-label" for="RAFI_GREET_HOME">Ana sayfada karşılama mesajı göster (oturum başına bir kez)</label></div>
		<button class="btn btn-primary">Kaydet</button>
	</form>
	<hr>
	<p class="small text-muted mb-1"><strong>Yapay zekâ seçenekleri:</strong> Şu an ek maliyet yok. Doğal dil üretimi istenirse (ör. OpenAI uyumlu bir hizmet ya da sunucuda açık kaynak model) ayrıca onay ve maliyet değerlendirmesi gerekir; bu modül hiçbir harici hizmete istek göndermez.</p>
</div></div></div>
<div class="col-lg-7"><div class="card"><div class="card-body">
	<form method="get" class="d-flex gap-2 mb-3"><input class="form-control" name="deneme" value="{$rafiQuery|escape}" placeholder="Deneme sorusu: ör. 1000 TL altı kulaklık"><button class="btn btn-outline-primary">Dene</button></form>
	{if $rafiPreview}
		<p><strong>Rafi:</strong> {$rafiPreview.reply|escape}</p>
		{if $rafiPreview.products}<ol class="small">{foreach $rafiPreview.products as $p}<li>{$p.name|escape} — {$p.price_text|escape} — {$p.stock_text|escape} — {$p.seller.name|escape}</li>{/foreach}</ol>{/if}
		{if $rafiPreview.compare}<div class="table-responsive"><table class="table table-sm small"><thead><tr><th></th>{foreach $rafiPreview.compare.columns as $c}<th>{$c.name|escape}</th>{/foreach}</tr></thead><tbody>{foreach $rafiPreview.compare.rows as $r}<tr><th>{$r.label|escape}</th>{foreach $r.values as $v}<td>{$v|escape}</td>{/foreach}</tr>{/foreach}</tbody></table></div>{/if}
	{else}<p class="text-muted small mb-0">Bir soru yazıp “Dene”ye basın; yanıt canlı katalog verisinden üretilir.</p>{/if}
</div></div></div>
</div>
