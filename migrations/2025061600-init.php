<?php

$exchangeBalancesFields = [
	'exchange_name' => "VARCHAR(64) NOT NULL DEFAULT ''"
];
$exchangeBalancesKeys = [
	'PRIMARY KEY' => ['exchange_name'],
];
$manager->createTable('exchange_balances', $exchangeBalancesFields, $exchangeBalancesKeys);
