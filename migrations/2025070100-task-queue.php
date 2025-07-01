<?php

$fields = [
	'task_id' => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
	'task_recipient' => "ENUM('Trader', 'Analyzer', 'Notifier') NOT NULL DEFAULT 'Trader'",
	'task_type' => "VARCHAR(32) NOT NULL DEFAULT 'unknown'", // There can be a lot of types in the future.
	'task_status' => "ENUM('Pending', 'InProgress', 'Completed', 'Failed') NOT NULL DEFAULT 'Pending'",
	'task_created_at' => "INT UNSIGNED NULL DEFAULT NULL",
	'task_attributes' => "TEXT NULL DEFAULT NULL",
];
$keys = [
	'PRIMARY KEY' => ['task_id'],
];
$manager->createTable('tasks', $fields, $keys);
