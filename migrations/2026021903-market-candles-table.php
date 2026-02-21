<?php

/**
 * Migration: Create market_candles table for live market candle data.
 *
 * Stores OHLCV candles fetched by Trader from the exchange API,
 * so that web interface and other components can access them without API calls.
 */
$fields = [
	'mc_id' => 'INT AUTO_INCREMENT PRIMARY KEY',
	'mc_exchange_name' => "VARCHAR(50) NOT NULL",
	'mc_ticker' => "VARCHAR(20) NOT NULL",
	'mc_market_type' => "ENUM('spot', 'futures') NOT NULL",
	'mc_timeframe' => "VARCHAR(10) NOT NULL",
	'mc_open_time' => "INT UNSIGNED NOT NULL",
	'mc_open' => "DECIMAL(20,8) NOT NULL",
	'mc_high' => "DECIMAL(20,8) NOT NULL",
	'mc_low' => "DECIMAL(20,8) NOT NULL",
	'mc_close' => "DECIMAL(20,8) NOT NULL",
	'mc_volume' => "DECIMAL(20,8) NOT NULL",
];
$keys = [
	'UNIQUE KEY uk_mc_series' => [
		'mc_exchange_name',
		'mc_ticker',
		'mc_market_type',
		'mc_timeframe',
		'mc_open_time',
	],
	'INDEX idx_mc_series_time' => [
		'mc_exchange_name',
		'mc_ticker',
		'mc_market_type',
		'mc_timeframe',
		'mc_open_time',
	],
];
$manager->createTable('market_candles', $fields, $keys);
