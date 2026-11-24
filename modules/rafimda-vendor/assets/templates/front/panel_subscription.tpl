<div class="rv-card">
	<h2>Paketiniz: {$eff.plan.name}</h2>
	{if $eff.expired}<div class="rv-alert warn">Paketinizin süresi doldu; Başlangıç paketine dönüldü. Mevcut ürünleriniz silinmez, ancak yeni kayıt limitleri Başlangıç paketine göre uygulanır.</div>{/if}
	{if $eff.expires_at}<p>Bitiş tarihi: <strong>{$eff.expires_at}</strong></p>{else}<p>Süresiz</p>{/if}
	<div class="rv-grid c3" style="margin:14px 0">
		<div class="rv-stat"><b>{$usage.products}{if $eff.plan.max_products !== null} / {$eff.plan.max_products}{/if}</b><span>Ürün{if $eff.plan.max_products === null} (sınırsız){/if}</span></div>
		<div class="rv-stat"><b>{$usage.xml}{if $eff.plan.max_xml_connections !== null} / {$eff.plan.max_xml_connections}{/if}</b><span>XML bağlantısı{if $eff.plan.max_xml_connections === null} (sınırsız){/if}</span></div>
		<div class="rv-stat"><b>{$usage.marketplaces}{if $eff.plan.max_marketplace_accounts !== null} / {$eff.plan.max_marketplace_accounts}{/if}</b><span>Pazaryeri hesabı{if $eff.plan.max_marketplace_accounts === null} (sınırsız){/if}</span></div>
	</div>
	<div class="rv-alert info">Paket yükseltme ve ödeme bu sürümde otomatik değildir: paket değişikliği için mağaza yönetimiyle iletişime geçin.</div>
</div>
<div class="rv-card"><h3>Mevcut paketler</h3>
	<table class="rv-resp"><thead><tr><th>Paket</th><th>Aylık</th><th>Ürün</th><th>XML</th><th>Pazaryeri</th></tr></thead><tbody>
	{foreach $plans as $p}<tr><td data-l="Paket"><strong>{$p.name}</strong></td><td data-l="Aylık">{$p.price_monthly|string_format:"%.2f"} ₺</td><td data-l="Ürün">{if $p.max_products === null}Sınırsız{else}{$p.max_products}{/if}</td><td data-l="XML">{if $p.max_xml_connections === null}Sınırsız{else}{$p.max_xml_connections}{/if}</td><td data-l="Pazaryeri">{if $p.max_marketplace_accounts === null}Sınırsız{else}{$p.max_marketplace_accounts}{/if}</td></tr>{/foreach}
	</tbody></table>
</div>
