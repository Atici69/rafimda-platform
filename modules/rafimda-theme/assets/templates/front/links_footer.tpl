<div class="rt-sellerbar">
	<div class="rt-sellerbar__inner">
		<strong>Rafımda ile büyüyün</strong>
		<span>Mağazanızı açın veya bayi ağınızı kurun; siparişlerinizi tek panelden yönetin.</span>
		{if $rtVendor}<a class="rt-btn rt-btn--orange rt-btn--sm" href="{$rtRegisterUrl|escape}">Satıcı Ol</a>
		<a class="rt-btn rt-btn--ghost rt-btn--sm" href="{$rtLoginUrl|escape}">Satıcı Girişi</a>{/if}
		{if $rtDealer}<a class="rt-btn rt-btn--orange rt-btn--sm" href="{$rtDealerApplyUrl|escape}">Bayi Başvurusu</a>
		<a class="rt-btn rt-btn--ghost rt-btn--sm" href="{$rtDealerLoginUrl|escape}">Bayi Girişi</a>{/if}
	</div>
</div>
