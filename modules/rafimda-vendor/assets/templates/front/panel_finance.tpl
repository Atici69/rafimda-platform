<div class="rv-grid c4" style="margin-bottom:20px">
	<div class="rv-stat"><b>{$balances.available|string_format:"%.2f"} ₺</b><span>Ödenebilir bakiye</span></div>
	<div class="rv-stat"><b>{$balances.pending|string_format:"%.2f"} ₺</b><span>Bekleyen bakiye</span></div>
	<div class="rv-stat"><b>{$balances.earned|string_format:"%.2f"} ₺</b><span>Toplam satış kazancı</span></div>
	<div class="rv-stat"><b>{$balances.paid_out|string_format:"%.2f"} ₺</b><span>Ödenen toplam</span></div>
</div>
<div class="rv-alert info">Komisyon oranınız %{$commission}. Teslimattan {$holdDays} gün sonra kazanç ödenebilir bakiyeye geçer. İptal/iadelerde kazanç düzeltme kaydıyla geri alınır. Defter kayıtları değiştirilemez.</div>
<div class="rv-card"><h3>Hesap hareketleri</h3>
	{if $entries}<table class="rv-resp"><thead><tr><th>Tarih</th><th>Tür</th><th>Açıklama</th><th>Tutar</th><th>Ödenebilir tarihi</th></tr></thead><tbody>
	{foreach $entries as $e}<tr><td data-l="Tarih">{$e.date_add}</td><td data-l="Tür">{$types[$e.entry_type]|default:$e.entry_type}</td><td data-l="Açıklama">{$e.note}</td>
		<td data-l="Tutar"><strong style="color:{if $e.amount < 0}var(--rv-bad){else}var(--rv-ok){/if}">{$e.amount|string_format:"%.2f"} ₺</strong></td><td data-l="Ödenebilir">{$e.available_at|default:'Teslimat bekleniyor'}</td></tr>{/foreach}
	</tbody></table>{else}<p>Henüz hareket yok.</p>{/if}
</div>
<div class="rv-card"><h3>Ödemeler</h3>
	{if $payouts}<table><thead><tr><th>Tarih</th><th>Tutar</th><th>Referans</th></tr></thead><tbody>{foreach $payouts as $p}<tr><td>{$p.date_add}</td><td>{$p.amount|string_format:"%.2f"} ₺</td><td>{$p.reference}</td></tr>{/foreach}</tbody></table>{else}<p>Henüz ödeme yapılmadı.</p>{/if}
</div>
