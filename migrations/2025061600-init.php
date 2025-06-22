<?php

$exchangeBalancesFields = [
	'name' => "VARCHAR(64) NOT NULL DEFAULT ''",
	'balance' => "DECIMAL(12,2) NOT NULL DEFAULT '0.00'",
	'updated' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
];
$exchangeBalancesKeys = [
	'PRIMARY KEY' => ['name'],
];
$manager->createTable('exchange_balances', $exchangeBalancesFields, $exchangeBalancesKeys);
