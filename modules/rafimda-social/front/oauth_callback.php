<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

// Resmî OAuth dönüşü: state (tek kullanımlık) doğrulanır; token yalnızca şifreli saklanır. Sonuç kullanıcıya kısa bir sayfayla gösterilir.
App::sendNoStoreHeaders();
global $domain;
$r = RsoPlanner::oauthCallback((string) Tools::getValue('state', ''), (string) Tools::getValue('code', ''), rtrim((string) $domain, '/') . '/sosyal-geri-donus');
$skipPageRender = true;
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sosyal medya bağlantısı</title>'
	. '<body style="font-family:sans-serif;max-width:560px;margin:40px auto;padding:0 16px"><h1 style="color:#0b2545">Sosyal medya bağlantısı</h1><p>'
	. htmlspecialchars($r['message'], ENT_QUOTES, 'UTF-8') . '</p><p><a href="' . htmlspecialchars(rtrim((string) $domain, '/') . '/', ENT_QUOTES, 'UTF-8') . '">Rafımda</a></p></body>';
exit;
