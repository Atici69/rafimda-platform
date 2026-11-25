{* Ürün bölümü: $rfTitle, $rfProducts, $rfUrl (boş olabilir), $rfExtra (başlık yanı, isteğe bağlı) *}
{if $rfProducts|default:[]|@count > 0}
<section class="rf-section">
	<div class="rf-section__head">
		<div class="rf-section__title-wrap"><h2 class="rf-section__title">{$rfTitle|escape}</h2>{$rfExtra|default:'' nofilter}</div>
		{if $rfUrl|default:'' != ''}<a href="{$rfUrl|escape}" class="rf-section__more">Tümünü Gör ›</a>{/if}
	</div>
	<div class="rf-grid">
		{foreach $rfProducts as $p}{if $p@iteration <= 12}{include file='./rf-card.tpl' p=$p}{/if}{/foreach}
	</div>
</section>
{/if}
