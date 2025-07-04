<?php

use Izzy\Financial\StoredPosition;

$manager->addTableColumn(
	StoredPosition::getTableName(),
	'position_finished_at',
	"TIMESTAMP NULL DEFAULT NULL"
);
