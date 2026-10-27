<?php declare(strict_types = 1);

// Standalone regression tests: php tests/backend.php (no Zabbix database required).
error_reporting(E_ALL);
if (!function_exists('_')) {
	function _($text) { return $text; }
}
set_error_handler(static function($severity, $message, $file, $line): void {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
define('ZBX_SORT_UP', 'ASC');
define('ZBX_SORT_DOWN', 'DESC');
define('ZBX_SLA_STATUS_ENABLED', 1);
define('ZBX_STYLE_GREEN', 'green');
define('ZBX_STYLE_RED', 'red');
define('ZBX_STYLE_TREEVIEW', 'treeview');
define('ZBX_STYLE_ARROW_RIGHT', 'arrow-right');
define('ZBX_STYLE_ARROW_DOWN', 'arrow-down');
define('EVENT_OBJECT_TRIGGER', 0);
define('ZBX_STYLE_LINK_ALT', 'link-alt');
define('ZBX_STYLE_HINTBOX_WRAP', 'hintbox-wrap');

class CController {
	public $response;
	protected function getInputs(array &$data, array $keys): void {}
	protected function hasInput($key): bool { return false; }
	protected function setResponse($response): void { $this->response = $response; }
	protected function checkAccess($permission): bool { return $permission === 'services'; }
}
class CControllerResponseData {
	public $data;
	public function __construct($data) { $this->data = $data; }
	public function setTitle($title): void {}
}
class CRoleHelper {
	const UI_SERVICES_SERVICES = 'services';
}
class HtmlStub {
	public $attributes = [];
	public function __construct(...$args) {}
	public function __call($method, $args) {
		if ($method === 'setAttribute') {
			$this->attributes[$args[0]] = $args[1];
		}
		return $this;
	}
}
class CCol extends HtmlStub {}
class CSpan extends HtmlStub {}
class CSimpleButton extends HtmlStub {}
class CLink extends HtmlStub {}
class CRow extends HtmlStub {}
class CUrl extends HtmlStub {
	public function getUrl(): string { return 'zabbix.php'; }
}
class CSeverityHelper {
	public static function getName($status): string { return (string) $status; }
	public static function getStatusStyle($status): string { return 'severity-'.$status; }
}

class API {
	public static $services = [];
	public static $calls = [];
	public static $members = ['1', '2'];
	public static $sli_values = [1 => 90.5, 2 => 99.7];
	public static function Service(): FakeService { return new FakeService(); }
	public static function Sla(): FakeSla { return new FakeSla(); }
	public static function Event(): FakeEvent { return new FakeEvent(); }
}
class FakeService {
	public function get(array $params): array {
		API::$calls[] = ['service', $params];
		$result = API::$services;
		if (isset($params['serviceids'])) {
			$result = array_intersect_key($result, array_flip($params['serviceids']));
		}
		if (isset($params['search'])) {
			$search = trim($params['search']['name'], '*');
			$result = array_filter($result, static function($service) use ($search): bool {
				return stripos($service['name'], $search) !== false;
			});
		}
		if (isset($params['slaids'])) {
			$result = array_intersect_key($result, array_flip(API::$members));
		}
		if (isset($params['selectProblemEvents'])) {
			foreach ($result as &$service) {
				$service['problem_events'] = (int) $service['status'] === -1 ? [] : [
					['eventid' => '100', 'name' => 'Inherited problem', 'severity' => '4']
				];
			}
			unset($service);
		}
		foreach ($result as &$service) {
			$service = array_intersect_key($service, array_fill_keys(array_merge($params['output'],
				isset($params['selectParents']) ? ['parents'] : [],
				isset($params['selectProblemEvents']) ? ['problem_events'] : []), true));
		}
		unset($service);
		return !empty($params['preservekeys']) ? $result : array_values($result);
	}
}
class FakeSla {
	public function get(array $params): array {
		API::$calls[] = ['sla', $params];
		check($params['filter']['status'] === 1, 'Disabled SLAs must be excluded.');
		return [['slaid' => '9', 'name' => 'Production', 'slo' => '99.9']];
	}
	public function getSli(array $params): array {
		API::$calls[] = ['sli', $params];
		$ids = array_reverse($params['serviceids']);
		return ['periods' => [['period_from' => 0]], 'serviceids' => $ids, 'sli' => [
			array_map(static function($id): array {
				return ['sli' => API::$sli_values[$id],
					'uptime' => (int) $id * 100, 'downtime' => 2, 'error_budget' => -60];
			}, $ids)
		]];
	}
}
class FakeEvent {
	public function get(array $params): array {
		API::$calls[] = ['event', $params];
		return ['100' => ['objectid' => '200', 'object' => 0]];
	}
}

require __DIR__.'/../actions/CControllerTreeService.php';
require __DIR__.'/../actions/CControllerTreeServiceView.php';
require __DIR__.'/../partials/module.services.tree.view.helpers.php';

class TestController extends Modules\ModTreeService\Actions\CControllerTreeService {
	public function load(array $filter = [], array $expanded = []): array {
		return $this->getData($this->cleanInput($filter + self::FILTER_FIELDS_DEFAULT), $expanded);
	}
	public function causes(array $ids): array { return $this->getRootCauses([], $ids); }
}
class TestPage extends Modules\ModTreeService\Actions\CControllerTreeServiceView {
	public function build(): void { $this->doAction(); }
	public function allowed(): bool { return $this->checkPermissions(); }
}
function check(bool $condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
}
function load(array $filter = [], array $expanded = []): array {
	API::$calls = [];
	return (new TestController())->load($filter + ['debug' => 1], $expanded);
}
function calls(string $type): array {
	return array_values(array_filter(API::$calls, static function($call) use ($type): bool {
		return $call[0] === $type;
	}));
}
function service(string $id, string $name, array $parents = [], int $status = -1): array {
	return ['serviceid' => $id, 'name' => $name, 'status' => $status,
		'parents' => array_map(static function($id): array { return ['serviceid' => $id]; }, $parents)];
}
API::$services = [
	1 => service('1', 'Root', [], 4),
	2 => service('2', 'Child', ['1'], 4),
	3 => service('3', 'Hidden leaf', ['2'], 4)
];

$page = new TestPage();
$page->build();
check(API::$calls === [], 'Initial page must not fetch data discarded before AJAX loading.');
check($page->allowed(), 'Use the Services UI permission.');

$data = load();
check($data['visible_serviceids'] === [1], 'Collapsed tree must load only root details.');
check(calls('sli')[0][1]['serviceids'] === [1], 'Hidden rows must not calculate SLI.');
check($data['services'][1]['sla']['sli'] === 90.5, 'Read period-major SLI response.');
check($data['services'][1]['sla']['uptime_sec'] === 100, 'Keep numeric uptime for sorting.');
check($data['services'][1]['sla']['error_budget'] === '-1m', 'Keep negative error budgets.');
check($data['services'][1]['root_causes'][0]['name'] === 'Inherited problem', 'Include inherited problems.');
check(!isset($data['services'][2]['sla']), 'Do not enrich hidden services.');
check(!isset($data['services'][2]['name']), 'Do not fetch hidden descendant names.');
$problem_calls = array_filter(calls('service'), static function($call): bool {
	return isset($call[1]['selectProblemEvents']);
});
check(count($problem_calls) === 1 && reset($problem_calls)[1]['serviceids'] === [1], 'Batch visible root causes.');
$rows = $rendered = [];
addServiceRow($data, $rows, '1', 0, false, '0', $rendered);
check(count($rows) === 1, 'Collapsed descendants must not create table rows.');
$graph = makeServiceGraphData($data);
check(count($graph['nodes']) === 1 && !$graph['edges'] && $graph['nodes'][0]['has_children'],
	'Graph must send only displayed nodes and preserve expansion affordance.');

$data = load(['sort' => 'uptime'], ['1']);
check(count(calls('sli')) === 1 && calls('sli')[0][1]['serviceids'] === [1, 2], 'Batch only visible SLA sort values.');
check($data['services'][1]['sla']['sli'] === 90.5 && $data['services'][2]['sla']['sli'] === 99.7,
	'Use returned service order, including multiple services.');
check($data['api_debug']['root_cause_services'] === 2, 'SLA sorting must not expand root cause scope.');
$graph = makeServiceGraphData($data);
check(count($graph['nodes']) === 2 && count($graph['edges']) === 1, 'Graph edges must stay inside displayed nodes.');

API::$services[2]['parents'] = [];
API::$services[3]['parents'] = [];
$data = load(['sort' => 'sla', 'sortorder' => 'DESC']);
check($data['root_services'] === [2, 1, 3], 'Sort numeric SLA values with missing values last.');
API::$services[2]['parents'] = [['serviceid' => '1']];
API::$services[3]['parents'] = [['serviceid' => '2']];
API::$sli_values[1] = -1;
$data = load();
check($data['services'][1]['sla']['sli'] === null && $data['services'][1]['sla']['sla_name'] === 'Production',
	'Undefined SLI must keep SLA membership without displaying a negative percentage.');
API::$sli_values[1] = 90.5;

load(['cols' => []]);
check(!calls('sla') && !calls('sli') && !calls('event'), 'Hidden columns must skip detail APIs.');
check(count(calls('service')) === 2, 'Collapsed page needs topology and visible names only.');
load(['cols' => ['slo', 'sla_name']]);
check(!calls('sli'), 'SLA configuration columns must not calculate SLI.');
load(['cols' => [], 'only_with_sla' => 1]);
check(!calls('sli'), 'SLA-only filter must use membership rather than SLI calculations.');
load(['cols' => [], 'graph_open' => 1]);
check(count(calls('sli')) === 1, 'Open graph loads SLA for its displayed nodes.');

$data = load(['name' => 'Hidden']);
check(count($data['services']) === 3 && $data['root_services'] === [1], 'Restore filtered ancestors in batches.');
check($data['api_debug']['parent_fetch_calls'] === 2, 'Fetch each missing ancestor once.');
$data = load(['status' => ['4', ['invalid']], 'cols' => ['', ['invalid']]]);
check($data['api_debug']['sla_services'] === 0, 'Normalize empty and malformed column selections.');

API::$services[4] = service('4', 'Other root');
API::$services[3]['parents'][] = ['serviceid' => '4'];
$data = load(['cols' => []], ['1', '2', '4']);
$rows = $rendered = [];
foreach ($data['root_services'] as $root) {
	addServiceRow($data, $rows, (string) $root, 0, false, '0', $rendered);
}
check(count($rows) === 4, 'Shared descendants must render once, without duplicated subtrees.');

// Regression for large collapsed installations: expensive calls remain proportional to visible rows.
API::$services = [1 => service('1', 'Root')];
for ($id = 2; $id <= 10001; $id++) {
	API::$services[$id] = service((string) $id, 'Hidden '.$id, ['1'], 4);
}
$data = load(['cols' => []]);
check($data['api_debug']['visible_services'] === 1 && count(calls('service')) === 2
	&& $data['api_debug']['name_services'] === 1,
	'10,000 collapsed children must not cause detail API calls.');
check(count(makeServiceGraphData($data)['nodes']) === 1, 'Large collapsed graph payload must stay small.');
echo "Backend regression checks passed (including 10,000 hidden services).\n";
