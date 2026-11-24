<div class="rd-card"><h3>Profil</h3>
	<table><tbody>
	<tr><th>Firma</th><td>{$dealer.company_name}</td></tr><tr><th>Yetkili</th><td>{$dealer.contact_name}</td></tr>
	<tr><th>Telefon</th><td>{$dealer.phone}</td></tr><tr><th>E-posta</th><td>{$dealer.email}</td></tr><tr><th>Şehir</th><td>{$dealer.city}</td></tr>
	<tr><th>Seviye</th><td>{$levelLabel}</td></tr>
	</tbody></table>
	<p style="font-size:13px;color:var(--rd-muted)">Bilgi değişikliği için yönetici ile iletişime geçin.</p>
</div>
<div class="rd-card" style="margin-top:16px"><h3>Referans bağlantınız</h3>
	<p>Müşterilerinizi bu bağlantıyla yönlendirin; bağlantıdan giren ziyaretçi 30 gün boyunca size bağlanır ve ilk siparişinde müşteriniz olur.</p>
	<input type="text" readonly value="{$refUrl}" onclick="this.select()">
	<p>Referans kodu: <strong>{$dealer.referral_code}</strong></p>
</div>
