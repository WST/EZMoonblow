<?php

use Izzy\Financial\Position;

// Entry Price → Initial Entry Price.
$manager->renameColumn(Position::getTableName(), 'position_entry_price', 'position_initial_entry_price');

// Add Average Entry Price.
$manager->addTableColumn(
	Position::getTableName(),
	'position_average_entry_price',
	'DECIMAL(20,8) NOT NULL'
);
