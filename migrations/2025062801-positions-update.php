<?php

use Izzy\Financial\Position;

$manager->modifyTableColumn(
	Position::getTableName(),
	'position_finished_at',
	"INT UNSIGNED NULL DEFAULT NULL"
);

$manager->modifyTableColumn(
	Position::getTableName(),
	'position_created_at',
	"INT UNSIGNED NULL DEFAULT NULL"
);

$manager->modifyTableColumn(
	Position::getTableName(),
	'position_updated_at',
	"INT UNSIGNED NULL DEFAULT NULL"
);
