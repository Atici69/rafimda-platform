<div class="rv-card">
	<div class="rv-toolbar" style="flex-wrap:wrap;align-items:center"><h2 style="margin:0;flex:1">Kazanç ve komisyon dökümü</h2>
		{foreach [7, 30, 90, 365] as $d}<a class="rv-btn sm {if $days == $d}navy{else}ghost{/if}" href="?s=finance&amp;days={$d}">Son {$d} gün</a>{/foreach}</div>
	<p style="color:var(--rv-muted);font-size:13px;margin-bottom:0">Mağazanız ücretsizdir; abonelik veya paket ücreti alınmaz. Yalnızca gerçekleşen satışlarınızdan komisyon kesilir. İptal ve iade edilen siparişlerden komisyon alınmaz.</p>
</div>
<div class="rv-grid c4" style="margin-bottom:20px">
	<div class="rv-stat"><b>{$st.gross|string_format:"%.2f"} ₺</b><span>Brüt satış ({$st.orders} sipariş)</span></div>
	<div class="rv-stat"><b>{$st.commission|string_format:"%.2f"} ₺</b><span>Komisyon (ort. %{$st.effective_rate|string_format:"%.2f"})</span></div>
	<div class="rv-stat"><b>{$st.deductions|string_format:"%.2f"} ₺</b><span>Toplam kesinti{if $st.adjustments < 0} (düzeltme {$st.adjustments|string_format:"%.2f"} ₺ dahil){/if}</span></div>
	<div class="rv-stat"><b>{$st.net|string_format:"%.2f"} ₺</b><span>Net kazanç</span></div>
</div>
{if $st.cancelled_orders > 0 || $st.adjustments > 0}<div class="rv-alert info">{if $st.cancelled_orders > 0}Bu dönemde {$st.cancelled_orders} sipariş ({$st.cancelled_gross|string_format:"%.2f"} ₺) iptal/iade edildi; bu siparişlerin {$st.cancelled_commission|string_format:"%.2f"} ₺ komisyonu kesilmedi. {/if}{if $st.adjustments > 0}Lehinize {$st.adjustments|string_format:"%.2f"} ₺ düzeltme yapıldı.{/if}</div>{/if}
<div class="rv-grid c2">
	<div class="rv-card"><h3>Komisyon nasıl hesaplanır?</h3>
		<p style="font-size:13px">Her ürün satırı için sırayla şuna bakılır: <strong>ürüne özel oran</strong> → <strong>kategori oranı</strong> → <strong>mağazanıza özel oran</strong> → <strong>genel oran</strong>. Katıldığınız kampanya oranı düşürüyorsa düşük oran uygulanır. Oran sipariş anında sabitlenir; sonraki değişiklikler eski siparişleri etkilemez.</p>
		<p style="font-size:13px">Mağazanızın standart oranı: <strong>%{$vendorRate|string_format:"%.2f"}</strong></p>
		{if $catRules}<table class="rv-resp"><thead><tr><th>Kategori</th><th>Oran</th></tr></thead><tbody>{foreach $catRules as $c}<tr><td data-l="Kategori">{$c.category_name}{if $c.include_children} <small style="color:var(--rv-muted)">(alt kategoriler dahil)</small>{/if}</td><td data-l="Oran"><strong>%{$c.rate|string_format:"%.2f"}</strong></td></tr>{/foreach}</tbody></table>{/if}
	</div>
	<div class="rv-card"><h3>Dönemdeki oran kaynakları</h3>
		{if $st.by_source}<table class="rv-resp"><thead><tr><th>Kaynak</th><th>Satır</th><th>Satış</th><th>Komisyon</th></tr></thead><tbody>{foreach $st.by_source as $b}<tr><td data-l="Kaynak">{$b.label}</td><td data-l="Satır">{$b.line_count}</td><td data-l="Satış">{$b.gross|string_format:"%.2f"} ₺</td><td data-l="Komisyon">{$b.commission|string_format:"%.2f"} ₺</td></tr>{/foreach}</tbody></table>{else}<p style="color:var(--rv-muted)">Bu dönemde satış yok.</p>{/if}
	</div>
</div>
<h2 style="margin:24px 0 12px">Bakiye</h2>
<div class="rv-grid c4" style="margin-bottom:20px">
	<div class="rv-stat"><b>{$balances.available|string_format:"%.2f"} ₺</b><span>Ödenebilir bakiye</span></div>
	<div class="rv-stat"><b>{$balances.pending|string_format:"%.2f"} ₺</b><span>Bekleyen bakiye</span></div>
	<div class="rv-stat"><b>{$balances.earned|string_format:"%.2f"} ₺</b><span>Toplam satış kazancı</span></div>
	<div class="rv-stat"><b>{$balances.paid_out|string_format:"%.2f"} ₺</b><span>Ödenen toplam</span></div>
</div>
<div class="rv-alert info">Teslimattan {$holdDays} gün sonra kazanç ödenebilir bakiyeye geçer. İptal/iadelerde kazanç düzeltme kaydıyla geri alınır. Defter kayıtları değiştirilemez.</div>
<div class="rv-card"><h3>Hesap hareketleri</h3>
	{if $entries}<table class="rv-resp"><thead><tr><th>Tarih</th><th>Tür</th><th>Açıklama</th><th>Tutar</th><th>Ödenebilir tarihi</th></tr></thead><tbody>
	{foreach $entries as $e}<tr><td data-l="Tarih">{$e.date_add}</td><td data-l="Tür">{$types[$e.entry_type]|default:$e.entry_type}</td><td data-l="Açıklama">{$e.note}</td>
		<td data-l="Tutar"><strong style="color:{if $e.amount < 0}var(--rv-bad){else}var(--rv-ok){/if}">{$e.amount|string_format:"%.2f"} ₺</strong></td><td data-l="Ödenebilir">{$e.available_at|default:'Teslimat bekleniyor'}</td></tr>{/foreach}
	</tbody></table>{else}<p>Henüz hareket yok.</p>{/if}
</div>
<div class="rv-card"><h3>Ödemeler</h3>
	{if $payouts}<table><thead><tr><th>Tarih</th><th>Tutar</th><th>Referans</th></tr></thead><tbody>{foreach $payouts as $p}<tr><td>{$p.date_add}</td><td>{$p.amount|string_format:"%.2f"} ₺</td><td>{$p.reference}</td></tr>{/foreach}</tbody></table>{else}<p>Henüz ödeme yapılmadı.</p>{/if}
</div>
