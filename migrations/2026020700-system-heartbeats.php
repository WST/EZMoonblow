<?php

$fields = [
	'component_name' => "VARCHAR(32) NOT NULL",
	'last_heartbeat' => "INT UNSIGNED NULL DEFAULT NULL",
	'status' => "ENUM('Running', 'Stopped', 'Error') NOT NULL DEFAULT 'Stopped'",
	'pid' => "INT UNSIGNED NULL DEFAULT NULL",
	'started_at' => "INT UNSIGNED NULL DEFAULT NULL",
	'extra_info' => "TEXT NULL DEFAULT NULL",
];
$keys = [
	'PRIMARY KEY' => ['component_name'],
];
$manager->createTable('system_heartbeats', $fields, $keys);
