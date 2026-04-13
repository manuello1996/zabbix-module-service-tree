<?php
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
*/

if (!function_exists('addServiceRow')) {
	function addServiceRow(array $data, array &$rows, string $serviceid, int $level, bool $parent_collapsed,
			string $current_parent_id): void {
		if (!array_key_exists($serviceid, $data['services'])) {
			return;
		}

		$service = $data['services'][$serviceid];
		$is_collapsed = $service['is_collapsed'];

		$toggle_tag = null;
		if ($service['children']) {
			$toggle_tag = (new CSimpleButton())
				->addClass(ZBX_STYLE_TREEVIEW)
				->addClass('js-toggle')
				->addItem(
					(new CSpan())->addClass($service['is_collapsed'] ? ZBX_STYLE_ARROW_RIGHT : ZBX_STYLE_ARROW_DOWN)
				);
			$toggle_tag->setAttribute(
				'data-service_id_'.$service['serviceid'],
				$service['serviceid']
			);
		}

		$name_col = new CCol();
		for ($i = 0; $i < $level * 5; $i++) {
			$name_col->addItem(NBSP_BG());
		}
		if ($toggle_tag !== null) {
			$name_col->addItem($toggle_tag);
		}
		else {
			$name_col->addItem((new CSpan())->addClass(ZBX_STYLE_TREEVIEW));
		}

		$service_link = (new CUrl('zabbix.php'))
			->setArgument('action', 'service.list')
			->setArgument('serviceid', $service['serviceid']);
		if ($service['path']) {
			$service_link->setArgument('path', $service['path']);
		}
		$name_col->addItem(new CLink($service['name'], $service_link));
		if (!empty($data['filter']['show_path']) && !empty($service['path_names'])) {
			$name_col->addItem((new CSpan(' ('.implode(' / ', $service['path_names']).')'))
				->addClass('service-breadcrumb'));
		}

		$service_status_value = array_key_exists('status_calc', $service) ? $service['status_calc'] : $service['status'];
		$status_meta = getServiceStatusMeta($service_status_value);
		$status_text = $status_meta['text'];
		$status_class = $status_meta['class'];

		$sli = $service['sla']['sli'];
		$slo = $service['sla']['slo'] ?? null;
		$sla_percent = ($sli !== null) ? (number_format((float) $sli, 2, '.', '') . '%') : '-';
		$slo_percent = ($slo !== null) ? ($slo . '%') : '-';
		$slo_class = '';
		if ($sli !== null && $slo !== null) {
			$slo_class = ((float) $sli >= (float) $slo) ? ZBX_STYLE_GREEN : ZBX_STYLE_RED;
		}

		$sla_name = $service['sla']['sla_name'] ?? '-';
		$slaid = $service['sla']['slaid'] ?? null;
		$sla_name_cell = $sla_name;
		if ($slaid !== null && $sla_name !== '-') {
			$sla_name_cell = new CLink($sla_name, (new CUrl('zabbix.php'))
				->setArgument('action', 'slareport.list')
				->setArgument('filter_slaid', $slaid)
				->setArgument('filter_date_from', '')
				->setArgument('filter_date_to', '')
				->setArgument('filter_set', 1));
		}

		$uptime = $service['sla']['uptime'] ?? '-';
		$downtime = $service['sla']['downtime'] ?? '-';
		$error_budget = $service['sla']['error_budget'] ?? '-';
		$downtime_sec = $service['sla']['downtime_sec'] ?? null;
		$error_budget_sec = $service['sla']['error_budget_sec'] ?? null;
		$downtime_class = '';
		if ($downtime_sec !== null && $error_budget_sec !== null && $error_budget_sec > 0) {
			if ($downtime_sec > ($error_budget_sec / 2)) {
				$downtime_class = ZBX_STYLE_RED;
			}
		}
		$downtime_style = '';
		if ($downtime_sec !== null && $downtime_sec > 0) {
			$downtime_style = 'color: #d9534f;';
		}
		$error_budget_style = '';
		if ($error_budget_sec !== null && $error_budget_sec < 0) {
			$error_budget_style = 'color: #f0ad4e;';
		}

		$path_names = $service['path_names'] ?? [];
		$breadcrumb_parts = $path_names;
		$breadcrumb_parts[] = $service['name'];
		$breadcrumb_text = implode(' / ', $breadcrumb_parts);

		$root_cause_export = '';
		if (array_key_exists('root_causes', $service) && $service['root_causes']) {
			$export_names = [];
			foreach ($service['root_causes'] as $problem) {
				$problem_name = $problem['name'] ?? '';
				if ($problem_name === '') {
					continue;
				}
				$export_names[] = $problem_name;
			}
			if ($export_names) {
				$root_cause_export = implode(' | ', $export_names);
			}
		}

		$root_cause_items = [];
		if (array_key_exists('root_causes', $service) && $service['root_causes']) {
			$problems = $service['root_causes'];
			$all_problem_names = [];
			foreach ($problems as $problem) {
				$problem_name = $problem['name'] ?? '';
				if ($problem_name !== '') {
					$all_problem_names[] = $problem_name;
				}
			}
			$max_show = 3;
			$shown = array_slice($problems, 0, $max_show);
			$remaining = count($problems) - count($shown);

			foreach ($shown as $problem) {
				$problem_name = $problem['name'] ?? '';
				$problem_eventid = $problem['eventid'] ?? null;
				$problem_triggerid = null;
				if (array_key_exists('object', $problem)
						&& defined('EVENT_OBJECT_TRIGGER')
						&& $problem['object'] == EVENT_OBJECT_TRIGGER) {
					$problem_triggerid = $problem['objectid'] ?? null;
				}

				if ($problem_eventid !== null && $problem_triggerid !== null) {
					$root_cause_items[] = (new CSpan([
						'* ',
						new CLink($problem_name, (new CUrl('tr_events.php'))
							->setArgument('triggerid', $problem_triggerid)
							->setArgument('eventid', $problem_eventid))
					]))
					->addClass('root-cause-item')
					->setAttribute('title', $problem_name);
				}
				else {
					$root_cause_items[] = (new CSpan('* '.$problem_name))
						->addClass('root-cause-item');
				}
			}

			if ($remaining > 0) {
				$more_link = (new CLink('['.$remaining.' more problem]'))
					->addClass(ZBX_STYLE_LINK_ALT)
					->setHint(implode("\n", $all_problem_names), ZBX_STYLE_HINTBOX_WRAP);
				$root_cause_items[] = (new CSpan($more_link))
					->addClass('root-cause-more');
			}
		}

		$table_row = new CRow([
			$name_col,
			(new CCol((new CSpan($status_text))->addClass($status_class))),
			(new CCol($sla_percent))->setAttribute('data-col', 'sla'),
			($slo_class ? (new CCol($slo_percent))->addClass($slo_class) : new CCol($slo_percent))
				->setAttribute('data-col', 'slo'),
			(new CCol($sla_name_cell))->setAttribute('data-col', 'sla_name'),
			(new CCol($uptime))->setAttribute('data-col', 'uptime'),
			($downtime_class ? (new CCol($downtime))->addClass($downtime_class) : new CCol($downtime))
				->addStyle($downtime_style)
				->setAttribute('data-col', 'downtime'),
			(new CCol($error_budget))
				->addStyle($error_budget_style)
				->setAttribute('data-col', 'error_budget'),
			(new CCol($root_cause_items))->setAttribute('data-col', 'root_cause')
		]);
		$table_row
			->setAttribute('data-name-path', $breadcrumb_text)
			->setAttribute('data-root-causes', $root_cause_export);

		if ($parent_collapsed) {
			$table_row->addClass(ZBX_STYLE_DISPLAY_NONE);
		}

		addParentServiceClass($data, $table_row, $current_parent_id);
		$rows[] = $table_row;

		foreach ($service['children'] as $child_serviceid) {
			addServiceRow($data, $rows, $child_serviceid, $level + 1, $parent_collapsed || $is_collapsed, $serviceid);
		}
	}
}

if (!function_exists('addParentServiceClass')) {
	function addParentServiceClass(array $data, &$element, string $parent_serviceid): void {
		if ($parent_serviceid !== '0' && array_key_exists($parent_serviceid, $data['services'])) {
			$element->setAttribute(
				'data-service_id_'.$parent_serviceid,
				$parent_serviceid
			);
		}
	}
}

if (!class_exists('CHtmlEntityBG', false)) {
	class CHtmlEntityBG {
		private $entity = '';

		public function __construct(string $entity) {
			$this->entity = $entity;
		}

		public function toString(): string {
			return $this->entity;
		}
	}
}

if (!function_exists('NBSP_BG')) {
	function NBSP_BG(): CHtmlEntityBG {
		return new CHtmlEntityBG('&nbsp;');
	}
}

if (!function_exists('getServiceStatusMeta')) {
	function getServiceStatusMeta($status): array {
		$status = (int) $status;
		if ($status == -1) {
			return ['text' => _('OK'), 'class' => ZBX_STYLE_GREEN];
		}
		if ($status >= 0 && $status <= 5) {
			return [
				'text' => CSeverityHelper::getName($status),
				'class' => CSeverityHelper::getStatusStyle($status)
			];
		}

		return ['text' => _('Problem'), 'class' => ZBX_STYLE_RED];
	}
}

