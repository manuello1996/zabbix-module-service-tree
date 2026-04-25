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

$root_causes = [];
foreach ($data['root_causes'] as $problem) {
	$problem_name = $problem['name'] ?? '';
	if ($problem_name === '') {
		continue;
	}

	$problem_url = null;
	$problem_eventid = $problem['eventid'] ?? null;
	$problem_triggerid = null;
	if (array_key_exists('object', $problem)
			&& defined('EVENT_OBJECT_TRIGGER')
			&& $problem['object'] == EVENT_OBJECT_TRIGGER) {
		$problem_triggerid = $problem['objectid'] ?? null;
	}

	if ($problem_eventid !== null && $problem_triggerid !== null) {
		$problem_url = (new CUrl('tr_events.php'))
			->setArgument('triggerid', $problem_triggerid)
			->setArgument('eventid', $problem_eventid)
			->getUrl();
	}

	$root_causes[] = [
		'name' => $problem_name,
		'severity' => (int)($problem['severity'] ?? 0),
		'url' => $problem_url
	];
}

echo json_encode([
	'serviceid' => (string) $data['serviceid'],
	'root_causes' => $root_causes
]);
