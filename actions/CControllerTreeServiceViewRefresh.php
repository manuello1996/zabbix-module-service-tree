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

use CControllerResponseData;
use CUrl;

/**
 * Controller for the Services tree asynchronous refresh endpoint.
 */
class CControllerTreeServiceViewRefresh extends CControllerTreeServiceView {

	protected function doAction(): void {
		// Build refresh data for the tree partial.
		$filter = static::FILTER_FIELDS_DEFAULT;
		$this->getInputs($filter, array_keys($filter));
		$filter = $this->cleanInput($filter);
		$expanded_services = explode(',', $this->getInput('expanded_services', '0'));
		$prepared_data = $this->getData($filter, $expanded_services);

		$view_url = (new CUrl('zabbix.php'))->setArgument('action', 'treeservice.view');
		foreach ($filter as $key => $value) {
			if (!in_array($key, ['page', 'graph_open', 'graph_root_causes'], true)) {
				$view_url->setArgument($key, $key === 'cols' && !$value ? [''] : $value);
			}
		}
		$view_url->setArgument('expanded_services', implode(',', $expanded_services));

		$data = [
			'last_refreshed' => time(),
			'filter' => $filter,
			'view_curl' => $view_url,
			'sort' => $filter['sort'],
			'sortorder' => $filter['sortorder']
		] + $prepared_data;

		$response = new CControllerResponseData($data);
		$this->setResponse($response);
	}
}
