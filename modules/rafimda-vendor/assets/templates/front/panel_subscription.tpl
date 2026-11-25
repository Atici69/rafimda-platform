{if $freeMode}<div class="rv-alert success"><strong>Mağazanız ücretsizdir.</strong> Rafımda'da mağaza açmak ve kullanmak için abonelik veya paket ücreti ödenmez; yalnızca gerçekleşen satışlarınızdan komisyon kesilir (bkz. <a href="?s=finance">Finans</a>). Aşağıdaki limitler teknik limitlerdir; artırım talebi ücretsizdir.</div>{/if}
<div class="rv-card">
	<h2>{if $freeMode}Mağaza planınız{else}Paketiniz{/if}: {$eff.plan.name}</h2>
	{if $eff.expired}<div class="rv-alert warning">Paketinizin süresi doldu; Başlangıç paketine dönüldü. Mevcut ürünleriniz silinmez, ancak yeni kayıt limitleri Başlangıç paketine göre uygulanır.</div>{/if}
	{if $eff.expires_at}<p>Bitiş tarihi: <strong>{$eff.expires_at}</strong></p>{else}<p>Süresiz</p>{/if}
	<div class="rv-grid c3" style="margin:14px 0">
		<div class="rv-stat"><b>{$usage.products}{if $eff.plan.max_products !== null} / {$eff.plan.max_products}{/if}</b><span>Ürün{if $eff.plan.max_products === null} (sınırsız){/if}</span></div>
		<div class="rv-stat"><b>{$usage.xml}{if $eff.plan.max_xml_connections !== null} / {$eff.plan.max_xml_connections}{/if}</b><span>XML bağlantısı{if $eff.plan.max_xml_connections === null} (sınırsız){/if}</span></div>
		<div class="rv-stat"><b>{$usage.marketplaces}{if $eff.plan.max_marketplace_accounts !== null} / {$eff.plan.max_marketplace_accounts}{/if}</b><span>Pazaryeri hesabı{if $eff.plan.max_marketplace_accounts === null} (sınırsız){/if}</span></div>
	</div>
	{if $freeMode}<div class="rv-alert info">Limit artırımı için <a href="?s=support">Destek</a> bölümünden talep oluşturabilirsiniz.</div>{else}<div class="rv-alert info">Paket yükseltme ve ödeme bu sürümde otomatik değildir: paket değişikliği için mağaza yönetimiyle iletişime geçin.</div>{/if}
</div>
<div class="rv-card"><h3>{if $freeMode}Limit grupları{else}Mevcut paketler{/if}</h3>
	<table class="rv-resp"><thead><tr><th>{if $freeMode}Grup{else}Paket{/if}</th>{if !$freeMode}<th>Aylık</th>{/if}<th>Ürün</th><th>XML</th><th>Pazaryeri</th></tr></thead><tbody>
	{foreach $plans as $p}<tr><td data-l="Paket"><strong>{$p.name}</strong></td>{if !$freeMode}<td data-l="Aylık">{$p.price_monthly|string_format:"%.2f"} ₺</td>{/if}<td data-l="Ürün">{if $p.max_products === null}Sınırsız{else}{$p.max_products}{/if}</td><td data-l="XML">{if $p.max_xml_connections === null}Sınırsız{else}{$p.max_xml_connections}{/if}</td><td data-l="Pazaryeri">{if $p.max_marketplace_accounts === null}Sınırsız{else}{$p.max_marketplace_accounts}{/if}</td></tr>{/foreach}
	</tbody></table>
</div>
{if $camp}
<div class="rv-card"><h3>Kampanyalarım</h3>
	<table class="rv-resp"><thead><tr><th>Kampanya</th><th>Başlangıç</th><th>Bitiş</th><th>Sipariş</th><th>Durum</th></tr></thead><tbody>
	{foreach $camp.mine as $m}<tr><td data-l="Kampanya"><strong>{$m.title}</strong></td><td data-l="Başlangıç">{$m.started_at}</td><td data-l="Bitiş">{if $m.expires_at}{$m.expires_at}{else}—{/if}</td><td data-l="Sipariş">{$m.orders_used}{if $m.max_orders !== null} / {$m.max_orders}{/if}</td>
		<td data-l="Durum"><span class="pill {if $m.live}ok{else}off{/if}">{if $m.live}Etkin{elseif $m.status == 'cancelled'}İptal edildi{else}Sona erdi{/if}</span></td></tr>
	{foreachelse}<tr><td colspan="5" style="text-align:center;color:var(--rv-muted);padding:20px">Henüz bir kampanyaya katılmadınız.</td></tr>{/foreach}
	</tbody></table>
</div>
{if $camp.joinable}
<div class="rv-card"><h3>Katılabileceğiniz kampanyalar</h3>
	{foreach $camp.joinable as $j}
	<form method="post" class="rv-toolbar" style="flex-wrap:wrap;align-items:center"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="action" value="campaign_join"><input type="hidden" name="id_campaign" value="{$j.id_campaign}">
		<div style="flex:1;min-width:220px"><strong>{$j.title}</strong>{if $j.description}<br><small style="color:var(--rv-muted)">{$j.description}</small>{/if}</div>
		{if $can.store}<button class="rv-btn" type="submit" data-confirm="Kampanyaya katılmak istiyor musunuz? Her kampanyaya yalnızca bir kez katılabilirsiniz.">Katıl</button>{/if}</form>
	{/foreach}
</div>
{/if}
{/if}
