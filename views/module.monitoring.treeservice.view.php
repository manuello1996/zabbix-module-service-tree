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


/**
 * @var CView $this
 */

$this->addJsFile('layout.mode.js');
$this->addJsFile('class.tabfilter.js');
$this->addJsFile('class.tabfilteritem.js');
$this->addJsFile('class.tagfilteritem.js');
$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');

$this->includeJsFile('monitoring.service.view.js.php');

$this->enableLayoutModes();
$web_layout_mode = $this->getLayoutMode();

$controls_list = new CList();
$controls_list->addItem(
	(new CLink(_('Export Excel'), '#'))
		->addClass(ZBX_STYLE_BTN)
		->addClass('js-export-csv')
);
$controls_list->addItem(get_icon('kioskmode', ['mode' => $web_layout_mode]));

$widget = (new CHtmlPage())
	->setTitle(_('Services tree'))
	->setWebLayoutMode($web_layout_mode)
	->setControls(
		(new CTag('nav', true, $controls_list))
			->setAttribute('aria-label', _('Content controls'))
	);

if ($web_layout_mode == ZBX_LAYOUT_NORMAL) {
	$columns = [
		['id' => 'sla', 'label' => _('SLA (%)')],
		['id' => 'slo', 'label' => _('SLO (%)')],
		['id' => 'sla_name', 'label' => _('SLA Name')],
		['id' => 'uptime', 'label' => _('Uptime')],
		['id' => 'downtime', 'label' => _('Downtime')],
		['id' => 'error_budget', 'label' => _('Error Budget')],
		['id' => 'root_cause', 'label' => _('Root cause')]
	];

	$cols_list = new CList();
	$selected_cols = $data['filter']['cols'] ?? array_column($columns, 'id');
	foreach ($columns as $column) {
		$checkbox = (new CCheckBox('cols[]', $column['id']))
			->setId('col_'.$column['id'])
			->setChecked(in_array($column['id'], $selected_cols));
		$cols_list->addItem(new CListItem([$checkbox, new CLabel($column['label'], 'col_'.$column['id'])]));
	}
	$cols_list->addClass('filter-columns');

	$filter_form = (new CForm())
		->setName('filter')
		->setMethod('get')
		->setAttribute('action', 'zabbix.php')
		->addItem(new CVar('action', 'treeservice.view'));

	$left_list = new CFormList('treeserviceFilterLeft');
	$left_list->addRow(
		new CLabel(_('Service name'), 'filter_name'),
		(new CTextBox('name', $data['filter']['name']))
			->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
			->setAttribute('id', 'filter_name')
			->setAttribute('placeholder', _('type here to search'))
	);

	$options_list = new CList();
	$only_problems = (new CCheckBox('only_problems', '1'))
		->setId('only_problems')
		->setChecked(!empty($data['filter']['only_problems']));
	$options_list->addItem(new CListItem([$only_problems, new CLabel(_('Only show problems'), 'only_problems')]));
	$show_path = (new CCheckBox('show_path', '1'))
		->setId('show_path')
		->setChecked(!empty($data['filter']['show_path']));
	$options_list->addItem(new CListItem([$show_path, new CLabel(_('Show breadcrumb path'), 'show_path')]));
	$only_with_sla = (new CCheckBox('only_with_sla', '1'))
		->setId('only_with_sla')
		->setChecked(!empty($data['filter']['only_with_sla']));
	$options_list->addItem(new CListItem([$only_with_sla, new CLabel(_('Only services with SLA'), 'only_with_sla')]));
	$options_list->addClass('filter-options');
	$left_list->addRow(new CLabel(_('Options')), $options_list);

	$status_options = [
		-1 => _('OK'),
		0 => _('Not classified'),
		1 => _('Information'),
		2 => _('Warning'),
		3 => _('Average'),
		4 => _('High'),
		5 => _('Disaster')
	];
	$status_list = new CList();
	foreach ($status_options as $value => $label) {
		$checkbox = (new CCheckBox('status[]', (string) $value))
			->setId('status_'.$value)
			->setChecked(in_array((string) $value, array_map('strval', $data['filter']['status'] ?? []), true));
		$status_list->addItem(new CListItem([$checkbox, new CLabel($label, 'status_'.$value)]));
	}
	$status_list->addClass('filter-status');

	$right_list = new CFormList('treeserviceFilterRight');
	$right_list->addRow(new CLabel(_('Status')), $status_list);

	$right_list->addRow(new CLabel(_('Columns')), $cols_list);

	$filter_form->addItem(
		(new CDiv([
			(new CDiv($left_list))->addClass('filter-left'),
			(new CDiv($right_list))->addClass('filter-right')
		]))->addClass('filter-grid')
	);
	$filter_form->addItem(
		(new CDiv([
			(new CSubmit('filter_set', _('Apply')))->addClass(ZBX_STYLE_BTN),
			(new CRedirectButton(_('Reset'), (new CUrl('zabbix.php'))->setArgument('action', 'treeservice.view')->getUrl()))
				->setId('filter_reset')
				->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('filter-forms-footer')
	);

	$filter_toggle = (new CLink('', '#'))
		->addClass('tabfilter-item-link')
		->addClass('js-filter-toggle')
		->addItem((new CSpan())->addClass('zi-filter'))
		->setAttribute('aria-label', _('Toggle filter'));

	$widget->addItem(
		(new CDiv([
			(new CDiv($filter_toggle))->addClass('filter-toggle'),
			(new CDiv($filter_form))->addClass('filter-body')
		]))
			->addClass('filter-container')
			->addClass('service-filter')
	);
}

$data['filter_options'] = null;

$widget->addItem((new CForm())->setName('host_view')->addClass('is-loading'));
$widget->show();

$this->addCssFile('modules/zabbix-module-service-tree/views/css/treeservice.css');

(new CScriptTag('
	view.init('.json_encode([
		'filter_options' => $data['filter_options'],
		'refresh_url' => $data['refresh_url'],
		'refresh_interval' => $data['refresh_interval']
	]).');
'))
	->setOnDocumentReady()
	->show();
