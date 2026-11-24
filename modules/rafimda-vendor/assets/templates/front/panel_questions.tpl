<div class="rv-card">
	<h2>Soru-Cevap</h2>
	{if $moderation}<div class="rv-alert info">Yanıtlarınız yönetici onayından sonra yayınlanır.</div>{/if}
	<p style="color:var(--rv-muted)">Yanıtlarda telefon, e-posta, IBAN veya bağlantı paylaşmayın; herkese açık gösterimde otomatik gizlenir.</p>
	<div class="rv-toolbar">{foreach ['open' => 'Yanıt bekleyen', 'answered' => 'Yanıtlanan', 'all' => 'Tümü'] as $k => $l}<a class="rv-btn{if $filter != $k} ghost{/if}" href="?s=questions&f={$k}">{$l}</a> {/foreach}</div>
	<h3>Ürün soruları</h3>
	{if !$qaAvailable}<div class="rv-alert warning">Ürün soru-cevap modülü (question) kurulu değil; yönetici kurduğunda burada görünür.</div>
	{elseif $productQuestions}
	{foreach $productQuestions as $q}
	<div class="rv-qa">
		<p class="rv-qa__meta"><strong>{$q.product_name}</strong> · {$q.date_add}{if $q.answer && !$q.active} · <span class="pill warn">onay bekliyor</span>{elseif $q.answer} · <span class="pill ok">yayında</span>{/if}</p>
		<p class="rv-qa__q">{$q.question}</p>
		{if $q.answer}<p class="rv-qa__a">↳ {$q.answer}</p>{/if}
		{if $canAnswer}
		<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="kind" value="product"><input type="hidden" name="id" value="{$q.id_question}">
			<label for="ans_p{$q.id_question}">{if $q.answer}Yanıtı güncelle{else}Yanıtınız{/if}</label>
			<textarea id="ans_p{$q.id_question}" name="answer" rows="2" maxlength="2000" required></textarea>
			<p><button class="rv-btn" type="submit">Yanıtla</button></p>
		</form>
		{/if}
	</div>
	{/foreach}
	{else}<p style="color:var(--rv-muted)">Bu filtrede ürün sorusu yok.</p>{/if}
	<h3>Mağaza soruları</h3>
	{if $storeQuestions}
	{foreach $storeQuestions as $q}
	<div class="rv-qa">
		<p class="rv-qa__meta">{$q.date_add} · <span class="pill {if $q.status == 'published'}ok{elseif $q.status == 'rejected'}bad{else}warn{/if}">{$storeStatuses[$q.status]}</span></p>
		<p class="rv-qa__q">{$q.question}</p>
		{if $q.answer}<p class="rv-qa__a">↳ {$q.answer}</p>{/if}
		{if $canAnswer && $q.status != 'rejected'}
		<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="kind" value="store"><input type="hidden" name="id" value="{$q.id_store_question}">
			<label for="ans_s{$q.id_store_question}">Yanıtınız</label>
			<textarea id="ans_s{$q.id_store_question}" name="answer" rows="2" maxlength="2000" required></textarea>
			<p><button class="rv-btn" type="submit">Yanıtla</button></p>
		</form>
		{/if}
	</div>
	{/foreach}
	{else}<p style="color:var(--rv-muted)">Henüz mağaza sorusu yok.</p>{/if}
</div>
