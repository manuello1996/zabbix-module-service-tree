<?php declare(strict_types = 1);

/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

namespace Modules\ModTreeService\Actions;

use CController;
use API;

abstract class CControllerTreeService extends CController {
	const FILTER_IDX = 'web.services.treeservices';
	const SLA_COLUMNS = ['sla', 'slo', 'sla_name', 'uptime', 'downtime', 'error_budget'];
	const SLA_METRIC_COLUMNS = ['sla', 'uptime', 'downtime', 'error_budget'];

	private $sla_definitions = null;
	private $service_slas = [];
	protected $api_stats = [];

	const FILTER_FIELDS_DEFAULT = [
		'name' => '',
		'status' => [],
		'only_problems' => 0,
		'show_path' => 0,
		'only_with_sla' => 0,
		'graph_root_causes' => 0,
		'graph_open' => 0,
		'debug' => 0,
		'cols' => ['sla', 'slo', 'sla_name', 'uptime', 'downtime', 'error_budget', 'root_cause'],
		'sort' => 'name',
		'sortorder' => ZBX_SORT_UP,
		'page' => null
	];

	protected function getData(array $filter, array $expanded_services): array {
		$this->api_stats = array_fill_keys([
			'services', 'visible_services', 'name_services', 'name_fetch_calls', 'parent_fetch_calls', 'sla_services', 'sla_membership_calls',
			'sla_getsli_calls', 'root_cause_services', 'root_cause_calls', 'event_calls'
		], 0);

		// Only IDs, topology and status are needed for global filters and summary counts.
		$params = [
			'output' => ['serviceid', 'status'],
			'selectParents' => ['serviceid'],
			'preservekeys' => true
		];
		if ($filter['name'] !== '') {
			$params['search'] = ['name' => '*'.$filter['name'].'*'];
			$params['searchWildcardsEnabled'] = true;
		}
		$all_services = API::Service()->get($params);
		if (!is_array($all_services)) {
			throw new \RuntimeException('Cannot load services.');
		}
		$this->api_stats['services'] = count($all_services);
		$services = array_filter($all_services, static function(array $service) use ($filter): bool {
			return (!$filter['only_problems'] || (int) $service['status'] !== -1)
				&& (!$filter['status'] || in_array((int) $service['status'], $filter['status'], true));
		});

		// SLA membership is a configuration lookup, not an SLI calculation for hidden rows.
		if ($filter['only_with_sla']) {
			$services = array_intersect_key($services, $this->getServiceSlas(array_keys($services)));
		}

		// Restore ancestors in batches. Reuse metadata already fetched before asking the API.
		$frontier = $services;
		$requested = array_fill_keys(array_keys($services), true);
		while ($frontier) {
			$missing = [];
			foreach ($frontier as $service) {
				foreach ($service['parents'] ?? [] as $parent) {
					$id = $parent['serviceid'];
					if (!isset($requested[$id])) {
						$missing[$id] = true;
						$requested[$id] = true;
					}
				}
			}
			$frontier = array_intersect_key($all_services, $missing);
			$missing = array_diff_key($missing, $all_services);
			foreach (array_chunk(array_keys($missing), 1000) as $chunk) {
				$parents = API::Service()->get([
					'output' => ['serviceid', 'status'],
					'serviceids' => $chunk,
					'selectParents' => ['serviceid'],
					'preservekeys' => true
				]);
				$this->api_stats['parent_fetch_calls']++;
				if (!is_array($parents)) {
					throw new \RuntimeException('Cannot load service ancestors.');
				}
				$frontier += $parents;
			}
			$services += $frontier;
		}

		$expanded = array_fill_keys($expanded_services, true);
		foreach ($services as &$service) {
			$service['parent_serviceids'] = array_values(array_filter(
				array_column($service['parents'] ?? [], 'serviceid'),
				static function($id) use (&$services): bool { return isset($services[$id]); }
			));
			$service['parent_serviceid'] = $service['parent_serviceids'][0] ?? '0';
			$service['children'] = [];
			$service['is_collapsed'] = !isset($expanded[$service['serviceid']]);
		}
		unset($service);
		$roots = [];
		foreach ($services as $id => $service) {
			if (!$service['parent_serviceids']) {
				$roots[] = $id;
			}
			foreach ($service['parent_serviceids'] as $parent_id) {
				$services[$parent_id]['children'][] = $id;
			}
		}

		$visible = $this->collectVisibleServiceIds($services, $roots);
		$this->api_stats['visible_services'] = count($visible);
		$name_ids = array_fill_keys($visible, true);
		foreach ($visible as $id) {
			$services[$id]['path'] = $this->buildServicePath($services, $id);
			$name_ids += array_fill_keys($services[$id]['path'], true);
		}
		// Hidden descendants need no names. Breadcrumb ancestors are displayed information too.
		foreach (array_chunk(array_keys($name_ids), 1000) as $chunk) {
			$named_services = API::Service()->get(['output' => ['serviceid', 'name'], 'serviceids' => $chunk]);
			$this->api_stats['name_fetch_calls']++;
			if (!is_array($named_services)) {
				throw new \RuntimeException('Cannot load displayed service names.');
			}
			foreach ($named_services as $service) {
				$services[$service['serviceid']]['name'] = $service['name'];
			}
		}
		$this->api_stats['name_services'] = count($name_ids);
		foreach ($name_ids as $id => $_) {
			$services[$id]['name'] = $services[$id]['name'] ?? _('Unknown');
		}
		$cols = $filter['cols'];
		$sort = $filter['sort'];
		$load_sla = $filter['graph_open'] || array_intersect($cols, self::SLA_COLUMNS)
			|| in_array($sort, self::SLA_COLUMNS, true);
		$load_metrics = $filter['graph_open'] || array_intersect($cols, self::SLA_METRIC_COLUMNS)
			|| in_array($sort, self::SLA_METRIC_COLUMNS, true);
		$sla_data = $load_sla ? $this->getSlaDataForServices($visible, (bool) $load_metrics) : [];
		$empty_sla = array_fill_keys([
			'sli', 'slo', 'sla_name', 'slaid', 'uptime', 'downtime', 'error_budget',
			'uptime_sec', 'downtime_sec', 'error_budget_sec'
		], null);
		foreach ($visible as $id) {
			$services[$id]['sla'] = ($sla_data[$id] ?? []) + $empty_sla;
			$services[$id]['path_names'] = array_map(static function($parent_id) use (&$services): string {
				return $services[$parent_id]['name'];
			}, $services[$id]['path']);
		}

		// Sorting needs only visible siblings. Hidden descendants are sorted on expansion.
		$compare = function($a, $b) use (&$services, $filter): int {
			return $this->compareServices($services, $a, $b, $filter);
		};
		usort($roots, $compare);
		foreach ($visible as $id) {
			if (!$services[$id]['is_collapsed']) {
				usort($services[$id]['children'], $compare);
			}
		}

		$root_cause_ids = in_array('root_cause', $cols, true)
			? array_values(array_filter($visible, static function($id) use (&$services): bool {
				return (int) $services[$id]['status'] !== -1;
			})) : [];
		$root_causes = $root_cause_ids ? $this->getRootCauses($services, $root_cause_ids) : [];
		$this->api_stats['root_cause_services'] = count($root_cause_ids);
		foreach ($visible as $id) {
			$services[$id]['root_causes'] = $root_causes[$id] ?? [];
		}
		$summary = array_fill_keys(range(-1, 5), 0);
		$expandable = [];
		foreach ($services as $id => $service) {
			$summary[(int) $service['status']]++;
			if ($service['children']) {
				$expandable[] = (string) $id;
			}
		}
		$result = [
			'root_services' => $roots,
			'services' => $services,
			'visible_serviceids' => $visible,
			'expandable_serviceids' => $expandable,
			'status_summary' => $summary,
			'root_causes_loaded_serviceids' => $root_cause_ids
		];
		if ($filter['debug']) {
			$result['api_debug'] = $this->api_stats;
		}
		return $result;
	}

	private function collectVisibleServiceIds(array $services, array $roots): array {
		$visible = [];
		$queue = $roots;
		for ($i = 0; $i < count($queue); $i++) {
			$id = $queue[$i];
			if (isset($visible[$id]) || !isset($services[$id])) {
				continue;
			}
			$visible[$id] = true;
			if (!$services[$id]['is_collapsed']) {
				foreach ($services[$id]['children'] as $child_id) {
					$queue[] = $child_id;
				}
			}
		}
		return array_keys($visible);
	}

	private function compareServices(array $services, $a, $b, array $filter): int {
		$sort = $filter['sort'];
		$keys = ['sla' => 'sli', 'uptime' => 'uptime_sec', 'downtime' => 'downtime_sec',
			'error_budget' => 'error_budget_sec'];
		$value_a = $sort === 'name' ? $services[$a]['name'] : ($services[$a]['sla'][$keys[$sort] ?? $sort] ?? null);
		$value_b = $sort === 'name' ? $services[$b]['name'] : ($services[$b]['sla'][$keys[$sort] ?? $sort] ?? null);
		if ($value_a === null || $value_b === null) {
			return ($value_a === null ? 1 : 0) <=> ($value_b === null ? 1 : 0);
		}
		$comparison = in_array($sort, ['name', 'sla_name'], true)
			? strnatcasecmp((string) $value_a, (string) $value_b)
			: (float) $value_a <=> (float) $value_b;
		if ($comparison === 0) {
			$comparison = strnatcasecmp($services[$a]['name'], $services[$b]['name']);
		}
		return $comparison * ($filter['sortorder'] === ZBX_SORT_DOWN ? -1 : 1);
	}

	private function buildServicePath(array $services, $id): array {
		$path = [];
		$seen = [$id => true];
		while (isset($services[$id])) {
			$id = $services[$id]['parent_serviceid'];
			if (!isset($services[$id]) || isset($seen[$id])) {
				break;
			}
			$seen[$id] = true;
			$path[] = $id;
		}
		return array_reverse($path);
	}

	private function getSlaDefinitions(): array {
		if ($this->sla_definitions === null) {
			$this->sla_definitions = API::Sla()->get([
				'output' => ['slaid', 'name', 'slo'],
				'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
				'sortfield' => ['name', 'slaid']
			]);
			if (!is_array($this->sla_definitions)) {
				throw new \RuntimeException('Cannot load SLAs.');
			}
		}
		return $this->sla_definitions;
	}

	// Cache membership within this request; never calculate SLI just to test membership.
	private function getServiceSlas(array $ids): array {
		if (!$ids) {
			return [];
		}
		$unknown = array_diff_key(array_fill_keys($ids, null), $this->service_slas);
		$this->service_slas += $unknown;
		foreach ($this->getSlaDefinitions() as $sla) {
			if (!$unknown) {
				break;
			}
			foreach (array_chunk(array_keys($unknown), 1000) as $chunk) {
				$matches = API::Service()->get([
					'output' => ['serviceid'], 'serviceids' => $chunk, 'slaids' => [$sla['slaid']]
				]);
				$this->api_stats['sla_membership_calls']++;
				if (!is_array($matches)) {
					throw new \RuntimeException('Cannot load SLA membership.');
				}
				foreach ($matches as $service) {
					$id = $service['serviceid'];
					$this->service_slas[$id] = $sla;
					unset($unknown[$id]);
				}
			}
		}
		return array_filter(array_intersect_key($this->service_slas, array_flip($ids)));
	}

	private function getSlaDataForServices(array $ids, bool $load_metrics): array {
		$slas = $this->getServiceSlas($ids);
		$this->api_stats['sla_services'] += count($ids);
		$data = [];
		$groups = [];
		foreach ($slas as $id => $sla) {
			$data[$id] = ['slaid' => $sla['slaid'], 'sla_name' => $sla['name'], 'slo' => $sla['slo']];
			$groups[$sla['slaid']][] = $id;
		}
		if (!$load_metrics) {
			return $data;
		}
		foreach ($groups as $slaid => $service_ids) {
			foreach (array_chunk($service_ids, 1000) as $chunk) {
				$response = API::Sla()->getSli(['slaid' => $slaid, 'serviceids' => $chunk, 'periods' => 1]);
				$this->api_stats['sla_getsli_calls']++;
				if (!is_array($response)) {
					throw new \RuntimeException('Cannot load SLA indicators.');
				}
				// Zabbix indexes SLI by period first, then by the returned serviceids order.
				$periods = $response['sli'] ?? [];
				$period = $periods ? end($periods) : [];
				foreach ($response['serviceids'] ?? [] as $index => $id) {
					$cell = $period[$index] ?? [];
					if (!isset($data[$id]) || !array_key_exists('sli', $cell)) {
						continue;
					}
					$data[$id]['sli'] = (float) $cell['sli'] >= 0 ? $cell['sli'] : null;
					foreach (['uptime', 'downtime', 'error_budget'] as $key) {
						$data[$id][$key.'_sec'] = (int) ($cell[$key] ?? 0);
						$data[$id][$key] = $this->formatDuration($data[$id][$key.'_sec']);
					}
				}
			}
		}
		return $data;
	}

	// Use Zabbix's service status propagation, including problems inherited from descendants.
	protected function getRootCauses(array $services, array $ids = []): array {
		$ids = $ids ?: array_keys($services);
		if (!$ids) {
			return [];
		}
		$problem_services = API::Service()->get([
			'output' => ['serviceid'], 'serviceids' => $ids,
			'selectProblemEvents' => ['eventid', 'name', 'severity']
		]);
		$this->api_stats['root_cause_calls']++;
		if (!is_array($problem_services)) {
			throw new \RuntimeException('Cannot load service problems.');
		}
		$eventids = [];
		foreach ($problem_services as $service) {
			$eventids += array_fill_keys(array_column($service['problem_events'], 'eventid'), true);
		}
		$events = [];
		foreach (array_chunk(array_keys($eventids), 1000) as $chunk) {
			$batch = API::Event()->get([
				'output' => ['objectid', 'object'], 'eventids' => $chunk, 'preservekeys' => true
			]);
			$this->api_stats['event_calls']++;
			if (!is_array($batch)) {
				throw new \RuntimeException('Cannot load service problem links.');
			}
			$events += $batch;
		}
		$result = [];
		foreach ($problem_services as $service) {
			$problems = $service['problem_events'];
			foreach ($problems as &$problem) {
				$problem += $events[$problem['eventid']] ?? [];
			}
			unset($problem);
			usort($problems, static function($a, $b): int {
				return (int) $b['severity'] <=> (int) $a['severity'];
			});
			$result[$service['serviceid']] = $problems;
		}
		return $result;
	}

	private function formatDuration(int $seconds): string {
		$remaining = abs($seconds);
		$parts = [];
		foreach (['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
			$value = intdiv($remaining, $size);
			$remaining %= $size;
			if ($value) {
				$parts[] = $value.$unit;
			}
		}
		return ($seconds < 0 ? '-' : '').($parts ? implode(' ', $parts) : '0s');
	}

	protected function cleanInput(array $input): array {
		$input['status'] = array_values(array_unique(array_map('intval', array_filter(
			is_array($input['status'] ?? null) ? $input['status'] : [],
			static function($status): bool {
				return is_scalar($status) && in_array((string) $status, ['-1', '0', '1', '2', '3', '4', '5'], true);
			}
		))));
		$input['cols'] = array_values(array_intersect(
			self::FILTER_FIELDS_DEFAULT['cols'],
			array_filter(is_array($input['cols'] ?? null) ? $input['cols'] : [], 'is_string')
		));
		return $input;
	}
}
