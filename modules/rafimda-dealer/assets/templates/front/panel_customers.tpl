<div class="rd-card"><h3>Müşterilerim</h3>
	<p style="color:var(--rd-muted);font-size:13px">Gizlilik gereği yalnızca ad, maskeli e-posta ve sipariş özeti gösterilir; adres ve telefon paylaşılmaz.</p>
	{if $customers}<div class="rd-resp"><table><thead><tr><th>Ad</th><th>E-posta</th><th>Sipariş</th><th>Toplam</th><th>Kaynak</th><th>Bağlanma</th></tr></thead><tbody>
	{foreach $customers as $c}<tr><td>{$c.user_full_name}</td><td>{$c.email_masked}</td><td>{$c.orders}</td><td>{$c.total|string_format:"%.2f"} ₺</td><td>{if $c.source == 'admin'}Yönetici{else}Referans{/if}</td><td>{$c.date_add}</td></tr>{/foreach}
	</tbody></table></div>
	{else}<p style="color:var(--rd-muted)">Henüz müşteriniz yok. Referans bağlantınızı paylaşın: müşteri bu bağlantıyla siteye girip ilk siparişini verdiğinde size bağlanır.</p>{/if}
</div>
