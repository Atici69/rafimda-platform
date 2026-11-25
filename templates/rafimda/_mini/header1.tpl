<header class="sm-header t4-header rf-header">
	<div class="rf-topbar">
		<div class="sm-container rf-topbar__inner">
			<span class="rf-topbar__slogan">Aradığınız Her Şey, Rafımda!</span>
			<nav class="rf-topbar__links" aria-label="Kısayollar">
				<a href="{$domain}satici-kayit">Rafımda'da Satış Yap</a>
				<a href="{$domain}truck">{'Order Traking'|translate}</a>
				<a href="{$domain}contact">Yardım &amp; Destek</a>
			</nav>
		</div>
	</div>
	<div class="sm-container t4-header__inner">
		{if $t4UseHeaderBuilder|default:false && $t4HeaderHtml|default:'' != ''}
			{$t4HeaderHtml nofilter}
		{else}
		{* Mobile *}
		<div class="sm-header-mobile t4-header-mobile">
			<div class="sm-header__row t4-header-mobile__row">
				<button type="button" class="sm-icon-btn t4-icon-btn" data-bs-toggle="offcanvas" data-bs-target="#primeMobileMenu" aria-label="{'Menu'|translate}">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16"/></svg>
				</button>
				<a href="{$domain}" class="sm-logo t4-logo" title="{$siteName|escape}"{if $t4PreviewMode|default:false} data-t4-chrome="logo"{/if}>
					<img src="{if $t4Logo|default:'' != ''}{$t4Logo|escape}{else}{$siteLogos.header|escape}{/if}?v={$minute|default:1}" alt="{$siteName|escape}" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'">
					<span class="t4-logo__text" style="display:none">{$siteName|escape}</span>
				</a>
				<div class="sm-header__actions t4-header-mobile__actions">
					{if $isLoggedIn}
					<div class="sm-header__action-slot">
						{include file='../plugin/notifications-dropdown.tpl'}
					</div>
					{/if}
					<a href="{$domain}{if $isLoggedIn}my-account{else}login{/if}" class="sm-icon-btn t4-icon-btn" title="{'My Account'|translate}" aria-label="{'My Account'|translate}">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
					</a>
					<a href="#" class="sm-icon-btn t4-icon-btn js-show-cart" title="{'Cart'|translate}" aria-label="{'Cart'|translate}">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
						{if $cart.count|default:0 > 0}<span class="sm-icon-btn__badge sm-cart-count-badge">{$cart.count}</span>{else}<span class="sm-icon-btn__badge sm-cart-count-badge d-none">0</span>{/if}
					</a>
				</div>
			</div>
			<div class="sm-search-wrap">
				{include file='../plugin/header-search.tpl' smSearchInputId='smSearchMobile'}
			</div>
		</div>

		{* Desktop — Rafımda pazaryeri başlığı *}
		<div class="sm-header-desktop t4-header-desktop rf-hd">
			<div class="rf-hd__top">
				<a href="{$domain}" class="sm-logo t4-logo rf-hd__logo" title="{$siteName|escape}">
					<img src="{if $t4Logo|default:'' != ''}{$t4Logo|escape}{else}{$siteLogos.header|escape}{/if}?v={$minute|default:1}" alt="{$siteName|escape}" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'">
					<span class="t4-logo__text" style="display:none">{$siteName|escape}</span>
				</a>
				<div class="sm-search-desktop rf-hd__search">
					{include file='../plugin/header-search.tpl' smSearchInputId='smSearchDesktop'}
				</div>
				<div class="rf-hd__tools">
					{if $isLoggedIn}
					<div class="sm-header__action-slot rf-hd__notify">
						{include file='../plugin/notifications-dropdown.tpl'}
					</div>
					{/if}
					<a href="{$domain}{if $isLoggedIn}my-account{else}login{/if}" class="rf-hd__tool">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
						<span>{if $isLoggedIn}{'My Account'|translate}{else}Giriş Yap{/if}</span>
					</a>
					<a href="{$domain}favorites" class="rf-hd__tool">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
						<span>Favorilerim</span>
						{if $favoriteCount|default:0 > 0}<span class="rf-hd__badge">{$favoriteCount}</span>{/if}
					</a>
					<a href="#" class="rf-hd__tool js-show-cart">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
						<span>Sepetim</span>
						<span class="rf-hd__badge sm-cart-count-badge{if $cart.count|default:0 <= 0} d-none{/if}">{$cart.count|default:0}</span>
					</a>
				</div>
			</div>

			<nav class="sm-header-menu rf-hd__nav" aria-label="{'Menu'|translate}">
				<div class="dropdown t4-cats-dropdown rf-hd__cats">
					<button type="button" class="rf-hd__cats-btn" id="t4CatsBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-haspopup="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16"/></svg>
						<span>TÜM KATEGORİLER</span>
					</button>
					<div class="dropdown-menu rf-mega" aria-labelledby="t4CatsBtn">
						{foreach $rtMenuTree|default:$menuCategories as $cat}
						<div class="rf-mega__col">
							<a href="{$domain}{$cat.category_link|escape}" class="rf-mega__top">{$cat.category_name|escape}</a>
							{foreach $cat.subcategories|default:[] as $sub}<a href="{$domain}{$sub.category_link|escape}" class="rf-mega__link">{$sub.category_name|escape}</a>{/foreach}
						</div>
						{/foreach}
					</div>
				</div>
				{assign var=rfMenu value=$rtMenuTree|default:$menuCategories}
				<ul class="sm-header-menu__list rf-hd__list">
					{foreach $rfMenu as $cat}{if $cat@iteration <= 10}
					<li class="rf-hd__item{if $cat.subcategories|default:[]|@count > 0} has-sub{/if}">
						<a href="{$domain}{$cat.category_link|escape}" class="rf-hd__link{if isset($category) && $category.category_link == $cat.category_link} is-active{/if}">{$cat.category_name|escape}</a>
						{if $cat.subcategories|default:[]|@count > 0}
						<div class="rf-hd__sub">
							<a href="{$domain}{$cat.category_link|escape}" class="rf-hd__sub-all">Tüm {$cat.category_name|escape}</a>
							{foreach $cat.subcategories as $sub}<a href="{$domain}{$sub.category_link|escape}">{$sub.category_name|escape}</a>{/foreach}
						</div>
						{/if}
					</li>
					{/if}{/foreach}
				</ul>
				<a href="{$domain}special" class="rf-hd__link rf-hd__link--hot">Fırsat Ürünleri</a>
			</nav>
		</div>
		{/if}
	</div>
</header>
