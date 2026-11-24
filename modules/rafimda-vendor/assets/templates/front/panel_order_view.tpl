<p><a href="{$listUrl}">← Siparişler</a></p>
<div class="rv-grid c2">
<div class="rv-card"><h3>Sipariş #{$vo.reference|default:$vo.id_order}</h3>
	<p>Durum: <span class="pill warn">{$statuses[$vo.status]|default:$vo.status}</span> · Tarih: {$vo.order_date}</p>
	<p><strong>{$vo.customer_name}</strong><br>{$vo.customer_phone}<br>{$vo.address_text}<br>{$vo.address_district} / {$vo.address_city}</p>
	<table><thead><tr><th>Ürün</th><th>Adet</th><th>Birim</th><th>Toplam</th></tr></thead><tbody>
	{foreach $vo.items as $i}<tr><td>{$i.product_name}</td><td>{$i.qty|string_format:"%g"}</td><td>{$i.unit_price|string_format:"%.2f"} ₺</td><td>{$i.total|string_format:"%.2f"} ₺</td></tr>{/foreach}
	</tbody></table>
	<p style="margin-top:12px">Satış: <strong>{$vo.subtotal|string_format:"%.2f"} ₺</strong> · Komisyon (%{$vo.commission_rate}): {$vo.commission_amount|string_format:"%.2f"} ₺ · <strong>Kazancınız: {$vo.vendor_earning|string_format:"%.2f"} ₺</strong></p>
</div>
<div class="rv-card"><h3>Kargo ve hazırlık</h3>
	{if $vo.tracking_number}<p>Kargo: {$vo.cargo_company} · Takip: <strong>{$vo.tracking_number}</strong></p>{/if}
	{if $canManage && ($vo.status == 'pending' || $vo.status == 'preparing' || $vo.status == 'shipped')}
	<form method="post"><input type="hidden" name="token" value="{$rvToken}"><input type="hidden" name="id_vendor_order" value="{$vo.id_vendor_order}">
		<label>Durum</label><select name="status"><option value="preparing">Hazırlanıyor</option><option value="shipped"{if $vo.status == 'shipped'} selected{/if}>Kargoya verildi</option></select>
		<label>Kargo firması</label><input type="text" name="cargo_company" value="{if $vo.cargo_company}{$vo.cargo_company}{else}{$defaultCargo|default:''}{/if}">
		<label>Takip numarası</label><input type="text" name="tracking_number" value="{$vo.tracking_number}">
		<p><button class="rv-btn" type="submit">Güncelle</button></p></form>
	{else}<p style="color:var(--rv-muted)">Bu sipariş için işlem yapılamaz.</p>{/if}
</div></div>
