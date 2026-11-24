<?php
if (!defined('IN_SCRIPT')) {
	exit;
}
if (!RdSwitch::enabled()) { // bayilik pasif: rota yokmuş gibi 404
	http_response_code(404);
	$container = '404';
	$pageTitle = 'Sayfa Bulunamadı';
	return;
}
$skipPageRender = true;
RdAuthFlow::ref();
