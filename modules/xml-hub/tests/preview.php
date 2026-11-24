<?php
/**
 * ÖNİZLEME ÜRETİCİ (geliştirme): XML Hub admin ekranlarını gerçek şablon + gerçek XhPanel ile, SQLite'a konan ÖRNEK VERİ ile HTML parçası olarak üretir.
 * Çıktı canlı veri DEĞİLDİR. Kullanım: php tests/preview.php <çıktı-dizini>
 */
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/libs/Smarty.class.php';

$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
@mkdir($out, 0777, true);
xh_fresh_db(false);
$db = $GLOBALS['db'];
$now = date('Y-m-d H:i:s');
$db->exec("INSERT INTO xh_suppliers (id_supplier,id_vendor,name,website,notes,active) VALUES (1,0,'Örnek Toptan A.Ş.','https://ornek-toptan.example','Günlük XML','1'),(2,0,'Demo Tekstil','https://demo-tekstil.example','',1)");
$db->exec("INSERT INTO xh_connections (id_connection,id_vendor,id_supplier,name,source_type,url,item_path,mapping,options,interval_minutes,status,last_run_at,next_run_at,fail_count) VALUES
 (1,0,1,'Örnek Toptan — Ürün Akışı','url','https://ornek-toptan.example/feed.xml','//product','{\"fields\":{\"name\":\"title\",\"price\":\"price\",\"stock\":\"qty\",\"sku\":\"code\"}}','{}',60,'active','$now','$now',0),
 (2,0,2,'Demo Tekstil — Dosya','file','','//item','{\"fields\":{\"name\":\"ad\",\"price\":\"fiyat\"}}','{}',0,'draft',NULL,NULL,0)");
$db->exec("INSERT INTO xh_runs (id_connection,id_vendor,kind,status,total,created,updated,unchanged,failed,skipped,message,started_at,finished_at) VALUES
 (1,0,'import','done',480,120,300,50,10,0,'Tamamlandı','$now','$now'),(1,0,'sync','running',480,0,210,10,0,0,'','$now',NULL),(1,0,'import','failed',0,0,0,0,0,0,'Kaynak 503 döndürdü','$now','$now')");
$db->exec("INSERT INTO xh_errors (id_connection,id_run,item_key,level,message) VALUES (1,1,'SKU-1042','error','Fiyat alanı sayı değil'),(1,1,'SKU-2210','error','Kategori eşleşmesi yok'),(1,1,'SKU-3305','warning','Görsel indirilemedi')");
$db->exec("INSERT INTO xh_items (id_connection,item_key,id_product,row_hash,data,state) VALUES (1,'SKU-1001',0,'a','{\"name\":\"Pamuklu Tişört\",\"price\":\"149.90\",\"stock\":\"25\"}','new'),(1,'SKU-1002',0,'b','{\"name\":\"Kot Pantolon\",\"price\":\"399.00\",\"stock\":\"8\"}','new')");

$smarty = new Smarty\Smarty();
$smarty->setCompileDir(sys_get_temp_dir() . '/xh_pv_' . getmypid());
@mkdir($smarty->getCompileDir(), 0777, true);
$smarty->registerPlugin('modifier', 'adminT', static fn($s) => $s);
$GLOBALS['smarty'] = $smarty;

$views = ['list' => [], 'new' => [], 'suppliers' => [], 'runs' => [], 'errors' => [], 'changes' => [], 'conn_general' => ['view' => 'conn', 'id' => 1], 'conn_mapping' => ['view' => 'conn', 'id' => 1, 'tab' => 'mapping'], 'conn_history' => ['view' => 'conn', 'id' => 1, 'tab' => 'history']];

foreach ($views as $name => $get) {
	$_GET = $get ?: ['view' => $name];
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$panel = new XhPanel(0, true, '');
	[$view, $vars] = $panel->handle(false);
	$vars['view'] = $view;
	$smarty->clearAllAssign();
	$smarty->assign($vars + ['xhFlash' => null, 'xhToken' => 'x', 'xhCanManage' => true, 'xhTplDir' => dirname(__DIR__) . '/assets/templates/front/', 'xhCss' => 'https://test.local/modules/xml-hub/assets/css/xh.css', 'xhJs' => 'https://test.local/modules/xml-hub/assets/js/xh.js', 'xhPending' => [], 'xhCronUrl' => 'https://rafimda.com.tr/xml-cron?q=cron&key=••••••••', 'pageTitle' => 'XML Hub', 'adminToken' => 'x']);

	try {
		$html = $smarty->fetch('file:' . dirname(__DIR__) . '/assets/templates/admin/admin.tpl');
	} catch (Throwable $e) {
		$html = '<pre>HATA ' . htmlspecialchars($e->getMessage()) . '</pre>';
		fwrite(STDERR, "$name: " . $e->getMessage() . "\n");
	}

	file_put_contents("$out/xml-$name.html", $html);
	echo "xml-$name: " . strlen($html) . " bayt\n";
}
