<?php

use Izzy\Financial\StoredPosition;

// Entry Price → Initial Entry Price.
$manager->renameColumn(StoredPosition::getTableName(), 'position_entry_price', 'position_initial_entry_price');

// Add Average Entry Price.
$manager->addTableColumn(
	StoredPosition::getTableName(),
	'position_average_entry_price',
	'DECIMAL(20,8) NOT NULL'
);
