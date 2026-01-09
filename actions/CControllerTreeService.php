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
use Exception;

abstract class CControllerTreeService extends CController {

	// Filter idx prefix.
	const FILTER_IDX = 'web.monitoring.treeservices';

	// Filter fields default values.
	const FILTER_FIELDS_DEFAULT = [
		'name' => '',
		'status' => [],
		'only_problems' => 0,
		'show_path' => 0,
		'only_with_sla' => 0,
		'cols' => [
			'sla',
			'slo',
			'sla_name',
			'uptime',
			'downtime',
			'error_budget',
			'root_cause'
		],
		'sort' => 'name',
		'sortorder' => ZBX_SORT_UP,
		'page' => null
	];

	/**
	 * Prepares the service list based on the given filter and sorting options.
	 *
	 * @param array  $filter                  Filter options.
	 * @param string $filter['name']          Filter services by name.
	 * @param int    $filter['page']          Page number.
	 * @param string $filter['sort']          Sorting field.
	 * @param string $filter['sortorder']     Sorting order.
	 *
	 * @return array
	 */
	protected function getData(array $filter, array $expanded_services): array {
		$service_params = [
			'output' => ['serviceid', 'name', 'status'],
			'selectParents' => ['serviceid'],
			'selectChildren' => ['serviceid'],
			'selectProblemTags' => ['tag', 'value'],
			'sortfield' => 'name',
			'sortorder' => $filter['sortorder']
		];

		if ($filter['name'] !== '') {
			$service_params['search'] = ['name' => '*'.$filter['name'].'*'];
			$service_params['searchWildcardsEnabled'] = true;
		}

		$services = API::Service()->get($service_params);
		$services = $this->array_sort($services, 'name', $filter['sortorder']);

		$services_by_id = [];
		$status_filter = array_map('intval', $filter['status'] ?? []);
		foreach ($services as $service) {
			if ($filter['only_problems'] && (int) $service['status'] === -1) {
				continue;
			}
			if ($status_filter && !in_array((int) $service['status'], $status_filter, true)) {
				continue;
			}
			$services_by_id[$service['serviceid']] = $service;
		}

		$missing_parent_ids = $this->collectMissingParentIds($services_by_id);
		while ($missing_parent_ids) {
			$parent_services = API::Service()->get([
				'output' => ['serviceid', 'name', 'status'],
				'serviceids' => $missing_parent_ids,
				'selectParents' => ['serviceid'],
				'selectChildren' => ['serviceid'],
				'selectProblemTags' => ['tag', 'value']
			]);
			foreach ($parent_services as $parent_service) {
				$services_by_id[$parent_service['serviceid']] = $parent_service;
			}

			$missing_parent_ids = $this->collectMissingParentIds($services_by_id);
		}

		$sla_data_prefetch = [];
		if (!empty($filter['only_with_sla'])) {
			$sla_data_prefetch = $this->getSlaDataForServices(array_keys($services_by_id));
			if ($sla_data_prefetch) {
				$keep_ids = array_fill_keys(array_keys($sla_data_prefetch), true);
				foreach ($services_by_id as $serviceid => $service) {
					if (!array_key_exists($serviceid, $keep_ids)) {
						continue;
					}
					$parent_id = $service['parents'] ? $service['parents'][0]['serviceid'] : '0';
					while ($parent_id !== '0' && array_key_exists($parent_id, $services_by_id)) {
						$keep_ids[$parent_id] = true;
						$parent = $services_by_id[$parent_id];
						$parent_id = $parent['parents'] ? $parent['parents'][0]['serviceid'] : '0';
					}
				}
				foreach ($services_by_id as $serviceid => $service) {
					if (!array_key_exists($serviceid, $keep_ids)) {
						unset($services_by_id[$serviceid]);
					}
				}
			}
			else {
				$services_by_id = [];
			}
		}

		if ($filter['only_problems']) {
			foreach ($services_by_id as $serviceid => $service) {
				if ((int) $service['status'] === -1) {
					unset($services_by_id[$serviceid]);
				}
			}
		}

		foreach ($services_by_id as &$service) {
			$service['parent_serviceid'] = $service['parents']
				? $service['parents'][0]['serviceid']
				: '0';
			$service['children'] = [];
			$service['is_collapsed'] = in_array($service['serviceid'], $expanded_services) ? false : true;
			$service['root_cause'] = 'N/A';
		}
		unset($service);

		foreach ($services_by_id as $serviceid => $service) {
			$parent_id = $service['parent_serviceid'];
			if ($parent_id !== '0' && array_key_exists($parent_id, $services_by_id)) {
				$services_by_id[$parent_id]['children'][] = $serviceid;
			}
		}
		foreach ($services_by_id as $serviceid => &$service) {
			$service['path'] = $this->buildServicePath($services_by_id, $serviceid);
			$service['path_names'] = $this->buildServicePathNames($services_by_id, $serviceid);
		}
		unset($service);

		foreach ($services_by_id as $serviceid => &$service) {
			if (!$service['children']) {
				continue;
			}
			usort($service['children'], function($a, $b) use ($services_by_id, $filter) {
				$name_a = $services_by_id[$a]['name'] ?? '';
				$name_b = $services_by_id[$b]['name'] ?? '';
				if ($filter['sortorder'] === 'DESC') {
					return strnatcasecmp($name_b, $name_a);
				}
				return strnatcasecmp($name_a, $name_b);
			});
		}
		unset($service);

		$root_services = [];
		foreach ($services_by_id as $serviceid => $service) {
			if ($service['parent_serviceid'] === '0' || !array_key_exists($service['parent_serviceid'], $services_by_id)) {
				$root_services[] = $serviceid;
			}
		}
		usort($root_services, function($a, $b) use ($services_by_id, $filter) {
			$name_a = $services_by_id[$a]['name'] ?? '';
			$name_b = $services_by_id[$b]['name'] ?? '';
			if ($filter['sortorder'] === 'DESC') {
				return strnatcasecmp($name_b, $name_a);
			}
			return strnatcasecmp($name_a, $name_b);
		});

		$visible_service_ids = $this->collectVisibleServiceIds($services_by_id, $root_services);
		$cols = $filter['cols'] ?? [];
		$load_sla = array_intersect($cols, ['sla', 'slo', 'sla_name', 'uptime', 'downtime', 'error_budget']);
		$load_root_cause = in_array('root_cause', $cols, true);

		if (!empty($filter['only_with_sla']) && $sla_data_prefetch) {
			$sla_data = array_intersect_key($sla_data_prefetch, array_flip($visible_service_ids));
		}
		else {
			$sla_data = $load_sla ? $this->getSlaDataForServices($visible_service_ids) : [];
		}
		foreach ($services_by_id as $serviceid => &$service) {
			if (array_key_exists($serviceid, $sla_data)) {
				$service['sla'] = $sla_data[$serviceid];
			}
			else {
				$service['sla'] = [
					'sli' => null,
					'slo' => null,
					'uptime' => null,
					'downtime' => null,
					'error_budget' => null,
					'downtime_sec' => null,
					'error_budget_sec' => null,
					'sla_name' => null,
					'slaid' => null
				];
			}
		}
		unset($service);

		$root_causes = $load_root_cause ? $this->getRootCauses($services_by_id, $visible_service_ids) : [];
		foreach ($services_by_id as $serviceid => &$service) {
			$service['root_causes'] = $root_causes[$serviceid] ?? [];
		}
		unset($service);

		$status_summary = [
			-1 => 0,
			0 => 0,
			1 => 0,
			2 => 0,
			3 => 0,
			4 => 0,
			5 => 0
		];
		foreach ($services_by_id as $service) {
			$status_value = (int)($service['status_calc'] ?? $service['status']);
			if (array_key_exists($status_value, $status_summary)) {
				$status_summary[$status_value]++;
			}
		}

		return [
			'root_services' => $root_services,
			'services' => $services_by_id,
			'status_summary' => $status_summary
		];
	}

	protected function array_sort($array, $on, $order='ASC')
	{
		if (!is_array($array)) {
			return [];
		}

		$new_array = array();
		$sortable_array = array();

		if (count($array) > 0) {
			foreach ($array as $k => $v) {
				if (is_array($v)) {
					foreach ($v as $k2 => $v2) {
						if ($k2 == $on) {
							$sortable_array[$k] = $v2;
						}
					}
				} else {
					$sortable_array[$k] = $v;
				}
			}
			switch ($order) {
				case 'ASC':
					asort($sortable_array, SORT_NATURAL);
					break;
				case 'DESC':
					arsort($sortable_array, SORT_NATURAL);
					break;
			}
			foreach ($sortable_array as $k => $v) {
				$new_array[$k] = $array[$k];
			}
		}

		return $new_array;
	}

	private function collectMissingParentIds(array $services_by_id): array {
		$missing = [];
		foreach ($services_by_id as $service) {
			if (empty($service['parents']) || !is_array($service['parents'])) {
				continue;
			}
			$parent_id = $service['parents'][0]['serviceid'];
			if (!array_key_exists($parent_id, $services_by_id)) {
				$missing[$parent_id] = true;
			}
		}

		return array_keys($missing);
	}

	private function collectVisibleServiceIds(array $services_by_id, array $root_services): array {
		$visible = [];
		$stack = $root_services;

		while ($stack) {
			$serviceid = array_shift($stack);
			if (!array_key_exists($serviceid, $services_by_id)) {
				continue;
			}

			$visible[$serviceid] = true;
			$service = $services_by_id[$serviceid];
			if (!$service['is_collapsed'] && $service['children']) {
				foreach ($service['children'] as $child_id) {
					$stack[] = $child_id;
				}
			}
		}

		return array_keys($visible);
	}

	private function buildServicePath(array $services_by_id, $serviceid): array {
		$path = [];
		$current_id = $serviceid;

		while (array_key_exists($current_id, $services_by_id)) {
			$parent_id = $services_by_id[$current_id]['parent_serviceid'] ?? '0';
			if ($parent_id === '0' || !array_key_exists($parent_id, $services_by_id)) {
				break;
			}
			array_unshift($path, $parent_id);
			$current_id = $parent_id;
		}

		return $path;
	}

	private function buildServicePathNames(array $services_by_id, $serviceid): array {
		$path_ids = $this->buildServicePath($services_by_id, $serviceid);
		$names = [];

		foreach ($path_ids as $path_id) {
			if (!array_key_exists($path_id, $services_by_id)) {
				continue;
			}
			$names[] = $services_by_id[$path_id]['name'] ?? '';
		}

		return array_values(array_filter($names, 'strlen'));
	}

	private function getSlaDataForServices(array $service_ids): array {
		if (!class_exists('API') || !method_exists('API', 'SLA')) {
			return [];
		}

		$service_ids = array_unique(array_map('intval', $service_ids));
		if (!$service_ids) {
			return [];
		}

		$slas = API::SLA()->get([
			'output' => ['slaid', 'name', 'slo']
		]);
		if (!is_array($slas) || !$slas) {
			return [];
		}

		$slis_by_service = [];
		$chunks = array_chunk($service_ids, 200);
		foreach ($slas as $sla) {
			if (!array_key_exists('slaid', $sla)) {
				continue;
			}
			foreach ($chunks as $service_chunk) {
				$sli_response = API::SLA()->getSli([
					'slaid' => $sla['slaid'],
					'serviceids' => $service_chunk,
					'periods' => 1,
					'period_from' => time()
				]);

				if (empty($sli_response) || !isset($sli_response['serviceids'], $sli_response['sli'])) {
					continue;
				}

				foreach ($sli_response['serviceids'] as $index => $serviceid) {
					if (array_key_exists($serviceid, $slis_by_service)) {
						continue;
					}
					$service_sli = $sli_response['sli'][$index] ?? [];
					$period_data = $service_sli ? end($service_sli) : [];
					if (!array_key_exists('sli', $period_data)) {
						continue;
					}

					$slis_by_service[$serviceid] = [
						'sli' => $period_data['sli'],
						'uptime' => $this->formatDuration((int)($period_data['uptime'] ?? 0)),
						'downtime' => $this->formatDuration((int)($period_data['downtime'] ?? 0)),
						'error_budget' => $this->formatDuration((int)($period_data['error_budget'] ?? 0)),
						'downtime_sec' => (int)($period_data['downtime'] ?? 0),
						'error_budget_sec' => (int)($period_data['error_budget'] ?? 0),
						'sla_name' => $sla['name'] ?? null,
						'slaid' => $sla['slaid'] ?? null,
						'slo' => $sla['slo'] ?? null
					];
				}
			}
		}

		return $slis_by_service;
	}

	private function getRootCauses(array $services_by_id, array $service_ids = []): array {
		if ($service_ids) {
			$services_by_id = array_intersect_key($services_by_id, array_flip($service_ids));
		}
		if (!$services_by_id) {
			return [];
		}

		$by_service = [];
		foreach ($services_by_id as $serviceid => $service) {
			if (empty($service['problem_tags']) || !is_array($service['problem_tags'])) {
				continue;
			}

			$tags = [];
			foreach ($service['problem_tags'] as $problem_tag) {
				if (!array_key_exists('tag', $problem_tag)) {
					continue;
				}
				$tags[] = [
					'tag' => $problem_tag['tag'],
					'value' => $problem_tag['value'] ?? ''
				];
			}

			if (!$tags) {
				continue;
			}

			try {
				$problems = API::Problem()->get([
					'output' => ['eventid', 'name', 'severity', 'objectid', 'object'],
					'tags' => $tags,
					'evaltype' => TAG_EVAL_TYPE_AND_OR,
					'sortfield' => 'eventid',
					'sortorder' => 'DESC'
				]);
			} catch (Exception $e) {
				continue;
			}

			if (!is_array($problems) || !$problems) {
				continue;
			}

			usort($problems, function($a, $b) {
				return (int)($b['severity'] ?? 0) <=> (int)($a['severity'] ?? 0);
			});

			$by_service[$serviceid] = $problems;
		}

		return $by_service;
	}

	private function formatDuration(int $seconds): string {
		$is_negative = $seconds < 0;
		$abs_seconds = abs($seconds);
		if ($abs_seconds <= 0) {
			return '0s';
		}

		$days = floor($abs_seconds / 86400);
		$hours = floor(($abs_seconds % 86400) / 3600);
		$minutes = floor(($abs_seconds % 3600) / 60);
		$secs = $abs_seconds % 60;

		$parts = [];
		if ($days > 0) {
			$parts[] = $days . 'd';
		}
		if ($hours > 0) {
			$parts[] = $hours . 'h';
		}
		if ($minutes > 0) {
			$parts[] = $minutes . 'm';
		}
		if ($secs > 0) {
			$parts[] = $secs . 's';
		}

		$formatted = implode(' ', $parts);

		return $is_negative ? '-' . $formatted : $formatted;
	}

	/**
	 * Get additional data for filters. Selected groups for multiselect, etc.
	 *
	 * @param array $filter  Filter fields values array.
	 *
	 * @return array
	 */
	protected function getAdditionalData($filter): array {
		$data = [];

		return $data;
	}

	/**
	 * Clean passed filter fields in input from default values required for HTML presentation. Convert field
	 *
	 * @param array $input  Filter fields values.
	 *
	 * @return array
	 */
	protected function cleanInput(array $input): array {
		if (!array_key_exists('status', $input) || !is_array($input['status'])) {
			$input['status'] = [];
		}
		if (!array_key_exists('only_problems', $input)) {
			$input['only_problems'] = 0;
		}
		if (!array_key_exists('show_path', $input)) {
			$input['show_path'] = 0;
		}
		if (!array_key_exists('only_with_sla', $input)) {
			$input['only_with_sla'] = 0;
		}

		if (!array_key_exists('cols', $input) || !is_array($input['cols'])) {
			$input['cols'] = self::FILTER_FIELDS_DEFAULT['cols'];
		}
		else {
			$input['cols'] = array_values($input['cols']);
		}

		return $input;
	}
}
