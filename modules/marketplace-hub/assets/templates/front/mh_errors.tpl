<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tarih</th><th>Pazaryeri</th><th>Ürün</th><th>Kod</th><th>Mesaj</th></tr></thead><tbody>
{foreach $errors as $e}<tr><td>{$e.date_add|escape}</td><td>{$e.marketplace|escape}</td><td>#{$e.id_product|escape}</td><td>{$e.code|escape}</td><td>{$e.message|escape}</td></tr>{foreachelse}<tr><td colspan="5" class="muted">Hata kaydı yok.</td></tr>{/foreach}
</tbody></table></div>
