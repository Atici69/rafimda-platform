<div class="container-fluid">
	<h2>{$pageTitle|escape}</h2>
	{if $rtFlash}<div class="alert alert-{$rtFlash.type|escape}">{$rtFlash.message|escape}</div>{/if}
	<div class="card" style="padding:16px;margin-bottom:16px">
		<h4>Entegrasyon durumu</h4>
		<table class="table"><thead><tr><th>Modül</th><th>Kurulu</th><th>Etkin</th></tr></thead><tbody>
		{foreach $rtMods as $m}<tr><td>{$m.label|escape} <code>{$m.name|escape}</code></td><td>{if $m.installed}evet{else}<b>hayır</b>{/if}</td><td>{if $m.enabled}evet{else}<b>hayır</b>{/if}</td></tr>{/foreach}
		</tbody></table>
		<p class="text-muted">Satıcı bağlantıları/bandı yalnızca <code>rafimda-vendor</code>, bayi bağlantıları/bandı yalnızca <code>rafimda-dealer</code> etkinse gösterilir. Ürün bölümleri veritabanındaki gerçek ürünlerden gelir; ürün yoksa bölüm gösterilmez.</p>
	</div>
	<form method="post" class="card" style="padding:16px">
		<input type="hidden" name="token" value="{$rtToken|escape}">
		<h4>Görünüm</h4>
		<label><input type="checkbox" name="hero" value="1" {if $rtSet.hero == '1'}checked{/if}> Ana sayfada Rafımda hero bölümünü ve kategori şeridini göster</label><br>
		<label><input type="checkbox" name="sections" value="1" {if $rtSet.sections == '1'}checked{/if}> Ana sayfada dinamik ürün bölümlerini göster (yeni, fırsat, çok satan, en beğenilen)</label><br>
		<label><input type="checkbox" name="footer" value="1" {if $rtSet.footer == '1'}checked{/if}> Alt bilgi, mobil menü ve kayıt sayfasında satıcı/bayi bağlantılarını göster</label>
		<p><label>Hero başlığı <input type="text" name="title" maxlength="120" value="{$rtSet.title|escape}" placeholder="Aradığınız Her Şey, Rafımda!" style="width:100%"></label></p>
		<p><label>Hero alt metni <input type="text" name="subtitle" maxlength="220" value="{$rtSet.subtitle|escape}" style="width:100%"></label></p>
		<p><label>Hero arka plan görseli (isteğe bağlı; /yol veya https://…) <input type="text" name="hero_image" value="{$rtSet.hero_image|escape}" style="width:100%"></label></p>
		<h4>Hero slaytları</h4>
		<p class="text-muted">İlk slayt yukarıdaki başlık/alt metin/görselden oluşur. Buraya en fazla {$rtSlideSlots|count} ek slayt ekleyin (başlığı boş alan gösterilmez). Hiç slayt girmezseniz, etkin satıcı/bayi modüllerine göre varsayılan slaytlar gösterilir.</p>
		<p><label>Otomatik geçiş süresi (saniye, 3–15) <input type="number" name="slide_seconds" min="3" max="15" value="{$rtSet.slide_seconds|escape}" style="width:90px"></label>
		<label style="margin-left:16px"><input type="checkbox" name="slides_off" value="1"> Hiç slayt girilmemişse varsayılan slaytları gösterme</label></p>
		{foreach $rtSlideSlots as $i => $b}
		<fieldset style="border:1px solid #e2e8f0;padding:10px;margin-bottom:10px;border-radius:8px"><legend>Slayt {$i+2}</legend>
			<input type="text" name="slide[{$i}][title]" maxlength="80" value="{$b.title|escape}" placeholder="Başlık" style="width:100%;margin-bottom:6px">
			<input type="text" name="slide[{$i}][text]" maxlength="160" value="{$b.text|escape}" placeholder="Kısa metin" style="width:100%;margin-bottom:6px">
			<input type="text" name="slide[{$i}][link]" value="{$b.link|escape}" placeholder="Bağlantı (/yol veya https://...)" style="width:100%;margin-bottom:6px">
			<input type="text" name="slide[{$i}][image]" value="{$b.image|escape}" placeholder="Arka plan görseli (isteğe bağlı)" style="width:100%;margin-bottom:6px">
			<input type="text" name="slide[{$i}][cta]" maxlength="30" value="{$b.cta|escape}" placeholder="Buton yazısı" style="width:100%">
		</fieldset>
		{/foreach}
		<h4>Banner / reklam alanları (başlığı boş olan alan gösterilmez)</h4>
		<p class="text-muted">Bağlantı ve görsel: <code>/yol</code> veya <code>https://…</code>. Görsel adresini kendi sunucunuzdaki bir dosyadan veriniz.</p>
		{foreach $rtBannerSlots as $i => $b}
		<fieldset style="border:1px solid #e2e8f0;padding:10px;margin-bottom:10px;border-radius:8px"><legend>Alan {$i+1}</legend>
			<input type="text" name="banner[{$i}][title]" maxlength="80" value="{$b.title|escape}" placeholder="Başlık" style="width:100%;margin-bottom:6px">
			<input type="text" name="banner[{$i}][text]" maxlength="160" value="{$b.text|escape}" placeholder="Kısa metin" style="width:100%;margin-bottom:6px">
			<input type="text" name="banner[{$i}][link]" value="{$b.link|escape}" placeholder="Bağlantı (/kategori/... veya https://...)" style="width:100%;margin-bottom:6px">
			<input type="text" name="banner[{$i}][image]" value="{$b.image|escape}" placeholder="Görsel adresi" style="width:100%;margin-bottom:6px">
			<input type="text" name="banner[{$i}][cta]" maxlength="30" value="{$b.cta|escape}" placeholder="Buton yazısı" style="width:100%">
		</fieldset>
		{/foreach}
		<button class="btn btn-primary" type="submit">Kaydet</button>
	</form>
</div>
