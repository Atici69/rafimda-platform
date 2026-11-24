<main class="rv-acc">
	<h1>Siparişimi değerlendir</h1>
	{if $rvFlash}<div class="rvs-flash rvs-flash--{$rvFlash.type|escape}" role="status">{$rvFlash.message|escape}</div>{/if}
	{if !$rvItems}
		<p>Bu sipariş değerlendirmeye uygun değil. Yalnızca <strong>teslim edilmiş</strong> siparişlerinizdeki ürünleri değerlendirebilirsiniz.</p>
		<p><a href="{$rvAccountUrl|escape}">Hesap merkezine dön</a></p>
	{else}
		<p class="rvs-small">Değerlendirmeniz "Doğrulanmış satın alma" etiketiyle, yönetici onayından sonra yayınlanır. Kişisel bilgi (telefon, e-posta, adres) yazmayın.</p>
		{foreach $rvItems as $it}
		<section>
			<h2 style="font-size:1.05rem">{$it.product_name|escape}{if $it.store_name} <small class="rvs-small">· {$it.store_name|escape}</small>{/if}</h2>
			{if $it.reviewed}<p class="rvs-badge">Bu ürünü değerlendirdiniz</p>
			{elseif $rvAvailable}
			<form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="{$rvToken|escape}"><input type="hidden" name="kind" value="product"><input type="hidden" name="id_product" value="{$it.id_product}">
				<fieldset class="rv-stars" aria-label="Puan">{for $s=5 to 1 step -1}<input type="radio" id="p{$it.id_product}s{$s}" name="rating" value="{$s}" required><label for="p{$it.id_product}s{$s}" title="{$s} yıldız">★</label>{/for}</fieldset>
				<label for="c{$it.id_product}">Yorumunuz</label><textarea id="c{$it.id_product}" name="comment" rows="3" minlength="10" maxlength="2000" required></textarea>
				<label for="ph{$it.id_product}">Fotoğraf (isteğe bağlı, en fazla 3, JPG/PNG/WEBP, 3 MB)</label><input type="file" id="ph{$it.id_product}" name="photos[]" accept=".jpg,.jpeg,.png,.webp" multiple>
				<p><button class="rvs-btn" type="submit">Ürünü değerlendir</button></p>
			</form>
			{else}<p class="rvs-small">Ürün yorumları şu an kapalı.</p>{/if}
		</section>
		{/foreach}
		{foreach $rvStores as $st}
		<section>
			<h2 style="font-size:1.05rem">Mağaza: {$st.store_name|escape}</h2>
			{if $st.reviewed}<p class="rvs-badge">Bu sipariş için mağazayı değerlendirdiniz</p>{else}
			<form method="post"><input type="hidden" name="token" value="{$rvToken|escape}"><input type="hidden" name="kind" value="store"><input type="hidden" name="id_vendor" value="{$st.id_vendor}">
				<fieldset class="rv-stars" aria-label="Mağaza puanı">{for $s=5 to 1 step -1}<input type="radio" id="v{$st.id_vendor}s{$s}" name="rating" value="{$s}" required><label for="v{$st.id_vendor}s{$s}" title="{$s} yıldız">★</label>{/for}</fieldset>
				<label for="vc{$st.id_vendor}">Satıcı ve teslimat deneyiminiz</label><textarea id="vc{$st.id_vendor}" name="comment" rows="3" minlength="10" maxlength="2000" required></textarea>
				<p><button class="rvs-btn" type="submit">Mağazayı değerlendir</button></p>
			</form>{/if}
		</section>
		{/foreach}
	{/if}
</main>
