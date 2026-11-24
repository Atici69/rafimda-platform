<div class="container-fluid">
	<h2>{$pageTitle|escape}</h2>
	{if $rtFlash}<div class="alert alert-{$rtFlash.type|escape}">{$rtFlash.message|escape}</div>{/if}
	<div class="card" style="padding:16px;margin-bottom:16px">
		<h4>Entegrasyon durumu</h4>
		<table class="table"><thead><tr><th>Modül</th><th>Kurulu</th><th>Etkin</th></tr></thead><tbody>
		{foreach $rtMods as $m}<tr><td>{$m.label|escape} <code>{$m.name|escape}</code></td><td>{if $m.installed}evet{else}<b>hayır</b>{/if}</td><td>{if $m.enabled}evet{else}<b>hayır</b>{/if}</td></tr>{/foreach}
		</tbody></table>
		<p class="text-muted">Satıcı bağlantıları yalnızca <code>rafimda-vendor</code> etkinse gösterilir. Modül kurulumu/etkinleştirme Modüller sayfasından yapılır.</p>
	</div>
	<form method="post" class="card" style="padding:16px">
		<input type="hidden" name="token" value="{$rtToken|escape}">
		<h4>Görünüm</h4>
		<label><input type="checkbox" name="hero" value="1" {if $rtSet.hero == '1'}checked{/if}> Ana sayfada Rafımda hero bölümünü göster</label><br>
		<label><input type="checkbox" name="footer" value="1" {if $rtSet.footer == '1'}checked{/if}> Alt bilgi, mobil menü ve kayıt sayfasında satıcı bağlantılarını göster</label>
		<p><label>Hero başlığı <input type="text" name="title" maxlength="120" value="{$rtSet.title|escape}" placeholder="Aradığınız Her Şey, Rafımda!" style="width:100%"></label></p>
		<p><label>Hero alt metni <input type="text" name="subtitle" maxlength="220" value="{$rtSet.subtitle|escape}" style="width:100%"></label></p>
		<button class="btn btn-primary" type="submit">Kaydet</button>
	</form>
</div>
