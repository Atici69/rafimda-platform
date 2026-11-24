<div class="rl-reg">
	<div class="form-check mb-2">
		<input class="form-check-input" type="checkbox" name="rl_membership" id="rl_membership" value="1" required>
		<label class="form-check-label" for="rl_membership">{if $rlLinks['uyelik-sozlesmesi']|default:''}<a href="{$rlLinks['uyelik-sozlesmesi']|escape}" target="_blank" rel="noopener">Üyelik Sözleşmesi</a>{else}Üyelik Sözleşmesi{/if}'ni kabul ediyorum ve {if $rlLinks['kvkk-aydinlatma']|default:''}<a href="{$rlLinks['kvkk-aydinlatma']|escape}" target="_blank" rel="noopener">KVKK Aydınlatma Metni</a>{else}KVKK Aydınlatma Metni{/if}'ni okudum. *</label>
	</div>
	<div class="form-check mb-2">
		<input class="form-check-input" type="checkbox" name="rl_commercial" id="rl_commercial" value="1">
		<label class="form-check-label" for="rl_commercial">Kampanya ve duyurulardan e-posta ile haberdar olmak istiyorum ({if $rlLinks['ticari-ileti-izni']|default:''}<a href="{$rlLinks['ticari-ileti-izni']|escape}" target="_blank" rel="noopener">Ticari Elektronik İleti Onay Metni</a>{else}Ticari Elektronik İleti Onay Metni{/if}). <small>İsteğe bağlı</small></label>
	</div>
	{if $rlLinks['acik-riza']|default:''}
	<div class="form-check mb-3">
		<input class="form-check-input" type="checkbox" name="rl_explicit" id="rl_explicit" value="1">
		<label class="form-check-label" for="rl_explicit"><a href="{$rlLinks['acik-riza']|escape}" target="_blank" rel="noopener">Açık Rıza Metni</a> kapsamında kişisel verilerimin işlenmesine rıza gösteriyorum. <small>İsteğe bağlı</small></label>
	</div>
	{/if}
</div>
