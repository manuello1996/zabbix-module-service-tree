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
**/

$this->includeJsFile('monitoring.host.view.refresh.js.php');

$form = (new CForm())->setName('host_view');

$table = (new CTableInfo())->addClass('services-tree');

$view_url = $data['view_curl']->getUrl();

// Status summary bar data.
$status_options = [
	-1 => ['label' => _('OK'), 'class' => ZBX_STYLE_GREEN],
	0 => ['label' => _('Not classified'), 'class' => CSeverityHelper::getStatusStyle(0)],
	1 => ['label' => _('Information'), 'class' => CSeverityHelper::getStatusStyle(1)],
	2 => ['label' => _('Warning'), 'class' => CSeverityHelper::getStatusStyle(2)],
	3 => ['label' => _('Average'), 'class' => CSeverityHelper::getStatusStyle(3)],
	4 => ['label' => _('High'), 'class' => CSeverityHelper::getStatusStyle(4)],
	5 => ['label' => _('Disaster'), 'class' => CSeverityHelper::getStatusStyle(5)]
];
$status_summary = $data['status_summary'] ?? [];
$status_list = new CList();
$total_count = array_sum($status_summary);
$status_list->addItem(
	(new CListItem([
		(new CSpan(_('Total')))->addClass('status-summary-label'),
		(new CSpan($total_count))->addClass('status-summary-count')->addClass('status-summary-total')
	]))->addClass('status-summary-item')
);
foreach ($status_options as $status_value => $meta) {
	$count = $status_summary[$status_value] ?? 0;
	$status_list->addItem(
		(new CListItem([
			(new CSpan($meta['label']))->addClass('status-summary-label'),
			(new CSpan($count))->addClass('status-summary-count')->addClass($meta['class'])
		]))->addClass('status-summary-item')
	);
}
$status_list->addClass('status-summary');

// Table header with sortable SLA columns.
$sla_header = make_sorting_header(_('SLA (%)'), 'sla', $data['sort'], $data['sortorder'], $view_url);
$sla_header = $sla_header instanceof CColHeader ? $sla_header : (new CColHeader($sla_header));
$sla_header->setAttribute('data-col', 'sla');

$slo_header = make_sorting_header(_('SLO (%)'), 'slo', $data['sort'], $data['sortorder'], $view_url);
$slo_header = $slo_header instanceof CColHeader ? $slo_header : (new CColHeader($slo_header));
$slo_header->setAttribute('data-col', 'slo');

$sla_name_header = make_sorting_header(_('SLA Name'), 'sla_name', $data['sort'], $data['sortorder'], $view_url);
$sla_name_header = $sla_name_header instanceof CColHeader ? $sla_name_header : (new CColHeader($sla_name_header));
$sla_name_header->setAttribute('data-col', 'sla_name');

$uptime_header = make_sorting_header(_('Uptime'), 'uptime', $data['sort'], $data['sortorder'], $view_url);
$uptime_header = $uptime_header instanceof CColHeader ? $uptime_header : (new CColHeader($uptime_header));
$uptime_header->setAttribute('data-col', 'uptime');

$downtime_header = make_sorting_header(_('Downtime'), 'downtime', $data['sort'], $data['sortorder'], $view_url);
$downtime_header = $downtime_header instanceof CColHeader ? $downtime_header : (new CColHeader($downtime_header));
$downtime_header->setAttribute('data-col', 'downtime');

$error_budget_header = make_sorting_header(_('Error Budget'), 'error_budget', $data['sort'], $data['sortorder'], $view_url);
$error_budget_header = $error_budget_header instanceof CColHeader ? $error_budget_header : (new CColHeader($error_budget_header));
$error_budget_header->setAttribute('data-col', 'error_budget');

// Table header with sortable columns.
$table->setHeader([
	make_sorting_header(_('Name'), 'name', $data['sort'], $data['sortorder'], $view_url),
	(new CColHeader(_('Status'))),
	$sla_header,
	$slo_header,
	$sla_name_header,
	$uptime_header,
	$downtime_header,
	$error_budget_header,
	(new CColHeader(_('Root cause')))->setAttribute('data-col', 'root_cause')
]);

// Render tree rows.
foreach ($data['root_services'] as $serviceid) {
	$rows = [];
	addServiceRow($data, $rows, $serviceid, 0, false, '0');
	foreach ($rows as $row) {
		$table->addRow($row);
	}
}

$form->addItem((new CDiv($status_list))->addClass('status-summary-wrap'));

// Action buttons below and above the table.
$expand_all = (new CButton('expand_all', _('Expand all')))
	->addClass(ZBX_STYLE_BTN_ALT)
	->addClass('js-expand-all');
$collapse_all = (new CButton('collapse_all', _('Collapse all')))
	->addClass(ZBX_STYLE_BTN_ALT)
	->addClass('js-collapse-all');
$form->addItem((new CDiv([$expand_all, $collapse_all]))->addClass('tree-actions'));
$form->addItem($table);
// Paging footer with total count.
$total_services = array_key_exists('services', $data) ? count($data['services']) : 0;
$paging = new CDiv(
	(new CTag('nav', true,
		(new CDiv(_('Displaying ').$total_services.' '._('services')))->addClass('table-stats')
	))
		->addClass('paging-btn-container')
		->setAttribute('role', 'navigation')
		->setAttribute('aria-label', _('Pager'))
);
$paging->addClass('table-paging');
$form->addItem($paging);

$form->addItem((new CDiv([$expand_all, $collapse_all]))->addClass('tree-actions'));
echo $form;

// Render a single service row and recurse into children.
function addServiceRow(array $data, array &$rows, string $serviceid, int $level, bool $parent_collapsed, string $current_parent_id): void {
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
					'• ',
					new CLink($problem_name, (new CUrl('tr_events.php'))
						->setArgument('triggerid', $problem_triggerid)
						->setArgument('eventid', $problem_eventid))
				]))
				->addClass('root-cause-item')
				->setAttribute('title', $problem_name);
			}
			else {
				$root_cause_items[] = (new CSpan('• '.$problem_name))
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

// Attach parent service ID as data attribute for collapse handling.
function addParentServiceClass(array $data, &$element, string $parent_serviceid): void {
	if ($parent_serviceid !== '0' && array_key_exists($parent_serviceid, $data['services'])) {
		$element->setAttribute(
			'data-service_id_'.$parent_serviceid,
			$parent_serviceid
		);
	}
}

// Non-breaking space helper for indentation.
function NBSP_BG(): CHtmlEntityBG {
	return new CHtmlEntityBG('&nbsp;');
}

// Lightweight HTML entity wrapper for spacing.
class CHtmlEntityBG {
	private $entity = '';
	public function __construct(string $entity) {
		$this->entity = $entity;
	}
	public function toString(): string {
		return $this->entity;
	}
}

// Map service status to label/style.
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
