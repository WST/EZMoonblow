<?php

/**
 * New column to store the expected TP price for the position.
 */

use Izzy\Financial\StoredPosition;

$manager->addTableColumn(
	StoredPosition::getTableName(),
	'position_expected_tp_price',
	"DECIMAL(20,8) NULL DEFAULT NULL",
);
