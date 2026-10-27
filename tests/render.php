<?php
// Integration with real Zabbix HTML classes; no database, login, or API calls.
// php tests/render.php /path/to/zabbix/ui
$ui = $argv[1] ?? '/usr/share/zabbix';
require $ui.'/include/defines.inc.php';
require $ui.'/include/func.inc.php';
require $ui.'/include/classes/core/CAutoloader.php';
$loader = new CAutoloader();
$loader->addNamespace('', array_filter(glob($ui.'/include/classes/*'), 'is_dir'));
$loader->addNamespace('', [$ui.'/include/classes/html/interfaces']);
$loader->register();
if (!function_exists('_')) {
	function _($text) { return $text; }
}
$_SERVER['SCRIPT_NAME'] = 'zabbix.php';
$_GET = [];
error_reporting(E_ALL);
set_error_handler(static function($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
CPartial::registerDirectory(__DIR__.'/../partials');
$empty_sla = array_fill_keys(['sli', 'slo', 'slaid'], null);
$root = [
	'serviceid' => '1', 'name' => 'Root <example>', 'status' => -1,
	'children' => ['2'], 'parent_serviceids' => [], 'path' => [], 'path_names' => [],
	'is_collapsed' => true, 'sla' => $empty_sla, 'root_causes' => []
];
$data = [
	'services' => ['1' => $root, '2' => ['name' => 'Hidden child']],
	'root_services' => ['1'], 'visible_serviceids' => ['1'],
	'filter' => ['graph_open' => 0, 'cols' => []], 'status_summary' => [-1 => 2],
	'view_curl' => (new CUrl('zabbix.php'))->setArgument('action', 'treeservice.view'),
	'sort' => 'name', 'sortorder' => ZBX_SORT_UP
];
$closed = (new CPartial('module.services.tree.view.html', $data))->getOutput();
if (!str_contains($closed, 'service-graph-panel is-collapsed')
		|| !str_contains($closed, 'aria-expanded="false"')
		|| str_contains($closed, 'Hidden child') || str_contains($closed, '&quot;nodes&quot;') || str_contains($closed, '<svg')
		|| !str_contains($closed, 'Root &lt;example&gt;')) {
	throw new RuntimeException('Collapsed HTML must omit hidden rows and graph data, and escape service names.');
}
$data['filter']['graph_open'] = 1;
$open = (new CPartial('module.services.tree.view.html', $data))->getOutput();
if (!str_contains($open, '&quot;nodes&quot;') || str_contains($open, 'service-graph-panel is-collapsed')) {
	throw new RuntimeException('Opening must add graph data for displayed services.');
}
preg_match_all('/\bid="([^"]+)"/', $open, $ids);
if (count($ids[1]) !== count(array_unique($ids[1]))) {
	throw new RuntimeException('Rendered controls must have unique IDs.');
}
echo "Zabbix HTML rendering checks passed.\n";
