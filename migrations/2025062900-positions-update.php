<?php

use Izzy\Financial\Position;

// First, give this field a better name.
$manager->renameColumn(Position::getTableName(), 'position_order_id', 'position_entry_order_id_on_exchange');

// Add another field to save the Exchange Id of the closing (Take Profit) order.
$manager->addTableColumn(
	Position::getTableName(),
	'position_tp_order_id_on_exchange',
	'VARCHAR(100) NULL'
);

// Add another field to save the Exchange Id of the closing (Take Profit) order.
$manager->addTableColumn(
	Position::getTableName(),
	'position_sl_order_id_on_exchange',
	'VARCHAR(100) NULL'
);
