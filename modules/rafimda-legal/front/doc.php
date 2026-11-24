<?php
if (!defined('IN_SCRIPT')) {
	exit;
}

global $smarty, $domain, $pageTitle, $pageDesc, $skipPageRender;

$slug = preg_replace('/[^a-z0-9-]/', '', (string) Tools::getValue('belge', '')) ?? '';
$item = $slug !== '' ? RlLegal::published($slug) : null;

if ($slug !== '' && !$item) {
	// taslak / pasif (ör. bayilik) belgeler ziyaretçiye hiç gösterilmez
	http_response_code(404);
	$container = '404';
	$pageTitle = 'Sayfa Bulunamadı';

	return;
}

$pageTitle = $item ? (string) $item['doc']['title'] : 'Hukuki Belgeler';
$pageDesc = $item ? (string) $item['doc']['title'] . ' — Rafımda' : 'Rafımda sözleşmeler, KVKK ve politikalar';
$skipPageRender = true;
$docs = $item ? [] : RlLegal::all(true);

$smarty->assign([
	'rlItem' => $item,
	'rlDocs' => $docs,
	'rlGroups' => RlLegal::GROUPS,
	'rlListUrl' => rtrim((string) $domain, '/') . '/hukuki',
	'rlCookieUrl' => rtrim((string) $domain, '/') . '/cerez-tercihleri',
	'pageName' => 'hukuki',
	'pageTitle' => $pageTitle,
	'documentTitle' => class_exists('Seo') ? Seo::formatDocumentTitle($pageTitle, 'cms') : $pageTitle,
	'pageDesc' => $pageDesc,
	'breadcrumb' => [['name' => 'Ana Sayfa', 'url' => $domain], ['name' => 'Hukuki Belgeler', 'url' => $item ? rtrim((string) $domain, '/') . '/hukuki' : ''], ...($item ? [['name' => $pageTitle, 'url' => '']] : [])],
]);

$smarty->display(_THEME_BASE_DIR_ . 'header.tpl');
$smarty->display('file:' . dirname(__DIR__) . '/assets/templates/front/doc.tpl');
$smarty->display(_THEME_BASE_DIR_ . 'footer.tpl');
