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
use CControllerResponseFatal;
use CRoleHelper;
use CUrl;

class CControllerTreeServiceView extends CControllerTreeService {

	// Disable CSRF since this view uses GET and refresh endpoints.
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	// Validate filter and sorting parameters.
	protected function checkInput(): bool {
		// Validate filter and sorting parameters.
		$fields = [
			'name' =>			'string',
			'status' =>			'array',
			'cols' =>			'array',
			'only_problems' =>			'in 0,1',
			'show_path' =>			'in 0,1',
			'only_with_sla' =>			'in 0,1',
			'graph_root_causes' =>			'in 0,1',
			'debug' =>			'in 0,1',
			'sort' =>			'in name,sla,slo,sla_name,uptime,downtime,error_budget',
			'sortorder' =>			'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'page' =>			'ge 1',
			'expanded_services' =>		'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	// Reuse standard permissions for Services access.
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	// Build initial page data and refresh URL.
	protected function doAction(): void {
		// Build initial page data and refresh URL.
		$filter = static::FILTER_FIELDS_DEFAULT;
		$this->getInputs($filter, array_keys($filter));
		$filter = $this->cleanInput($filter);
		$refresh_curl = new CUrl('zabbix.php');
		$filter['action'] = 'treeservice.view.refresh';
		array_map([$refresh_curl, 'setArgument'], array_keys($filter), $filter);
		$expanded_services = [];
		if ($this->hasInput('expanded_services')) {
			$expanded_services = explode(",", $this->getInput('expanded_services'));
			$refresh_curl->setArgument('expanded_services', $this->getInput('expanded_services'));
		}

		$data = [
			'last_refreshed' => time(),
			'refresh_url' => $refresh_curl->getUrl(),
			'refresh_interval' => 3600000,
			'filter' => $filter,
			'tabfilter_options' => null
		] + $this->getData($filter, $expanded_services);

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Services tree'));
		$this->setResponse($response);
	}
}
