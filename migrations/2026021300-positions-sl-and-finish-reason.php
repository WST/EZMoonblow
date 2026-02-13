<?php

/**
 * Migration: Add stop-loss fields and finish reason to positions table.
 *
 * - position_expected_sl_percent: expected stop-loss distance from entry (%)
 * - position_stop_loss_price: actual SL price on the exchange
 * - position_finish_reason: records how the position was closed (TP, SL, liquidation)
 */

use Izzy\Financial\StoredPosition;

$table = StoredPosition::getTableName();

$manager->addTableColumn(
	$table,
	'position_expected_sl_percent',
	"DECIMAL(5,2) NOT NULL DEFAULT '0.00' AFTER position_expected_tp_price",
);

$manager->addTableColumn(
	$table,
	'position_stop_loss_price',
	"DECIMAL(20,8) NULL DEFAULT NULL AFTER position_expected_sl_percent",
);

$manager->addTableColumn(
	$table,
	'position_finish_reason',
	"ENUM('TAKE_PROFIT_LIMIT', 'TAKE_PROFIT_MARKET', 'STOP_LOSS_LIMIT', 'STOP_LOSS_MARKET', 'LIQUIDATION') NULL DEFAULT NULL AFTER position_stop_loss_price",
);
