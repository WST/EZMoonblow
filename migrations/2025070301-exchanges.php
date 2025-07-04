<?php

/**
 * General info on the Exchanges.
 */

use Izzy\Financial\Position;

$exchangesFields = [
	'exchange_name' => "VARCHAR(32) NOT NULL DEFAULT 'unknown'",
	'exchange_updated_at' => "INT UNSIGNED NULL DEFAULT NULL",
	'exchange_balance' => "DECIMAL(12,2) NOT NULL DEFAULT '0.00'",
	'exchange_unrealized_pnl' => "DECIMAL(12,2) NOT NULL DEFAULT '0.00'",
];
$exchangesKeys = [
	'PRIMARY KEY' => ['exchange_name'],
];
$manager->createTable('exchanges', $exchangesFields, $exchangesKeys);

/**
 * Applications (Trader, Analyzer, etc.)
 */
$applicationsFields = [
	'application_name' => "VARCHAR(32) NOT NULL DEFAULT 'unknown'",
	'application_updated_at' => "INT UNSIGNED NULL DEFAULT NULL",
];
$applicationsKeys = [
	'PRIMARY KEY' => ['application_name'],
]; 

$manager->createTable('applications', $applicationsFields, $applicationsKeys);

/**
 * New column to store the expected profit for the position.
 */
$manager->addTableColumn(
	Position::getTableName(),
	'position_expected_profit_percent',
	"DECIMAL(5,2) NOT NULL DEFAULT '0.00'",
);
