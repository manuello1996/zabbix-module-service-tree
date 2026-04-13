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
require_once __DIR__.'/module.services.tree.view.helpers.php';

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

