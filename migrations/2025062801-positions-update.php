<?php

use Izzy\Financial\StoredPosition;

$manager->modifyTableColumn(
	StoredPosition::getTableName(),
	'position_finished_at',
	"INT UNSIGNED NULL DEFAULT NULL"
);

$manager->modifyTableColumn(
	StoredPosition::getTableName(),
	'position_created_at',
	"INT UNSIGNED NULL DEFAULT NULL"
);

$manager->modifyTableColumn(
	StoredPosition::getTableName(),
	'position_updated_at',
	"INT UNSIGNED NULL DEFAULT NULL"
);
