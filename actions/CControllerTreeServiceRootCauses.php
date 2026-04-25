<?php declare(strict_types = 1);

/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**/

namespace Modules\ModTreeService\Actions;

use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;

class CControllerTreeServiceRootCauses extends CControllerTreeService {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'serviceid' => 'required|ge 1'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
	}

	protected function doAction(): void {
		$this->api_stats = [
			'problem_tag_calls' => 0,
			'problem_group_calls' => 0
		];

		$serviceid = (string) $this->getInput('serviceid');
		$root_causes = $this->getRootCauses([$serviceid => ['serviceid' => $serviceid]], [$serviceid]);

		$this->setResponse(new CControllerResponseData([
			'serviceid' => $serviceid,
			'root_causes' => $root_causes[$serviceid] ?? []
		]));
	}
}
