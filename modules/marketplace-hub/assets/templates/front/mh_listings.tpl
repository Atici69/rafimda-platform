<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Ürün</th><th>Pazaryeri</th><th>Durum</th><th>Deneme</th><th>Not</th></tr></thead><tbody>
{foreach $listings as $l}<tr><td>#{$l.id_product|escape}</td><td>{$l.marketplace|escape}</td><td><span class="pill">{$l.state|escape}</span></td><td>{$l.attempts|escape}</td><td>{$l.error|escape}</td></tr>{foreachelse}<tr><td colspan="5" class="muted">Kayıt yok.</td></tr>{/foreach}
</tbody></table></div>
