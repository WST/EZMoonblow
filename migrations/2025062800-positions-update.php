<?php

use Izzy\Financial\Position;

$manager->addTableColumn(
	Position::getTableName(),
	'position_finished_at',
	"TIMESTAMP NULL DEFAULT NULL"
);
