<?php

$manager->addTableColumn(
	'system_heartbeats',
	'heartbeat_memory_usage',
	"BIGINT UNSIGNED NULL DEFAULT NULL AFTER heartbeat_extra_info",
);
