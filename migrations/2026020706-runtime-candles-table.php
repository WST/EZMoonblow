<?php

/**
 * Migration: Create runtime_candles table for runtime candle data.
 *
 * Stores OHLCV candle history requested at runtime by indicators/strategies.
 * Structure mirrors the candles table, but uses runtime_candle_ prefix.
 */
$fields = [
	'runtime_candle_id' => 'INT AUTO_INCREMENT PRIMARY KEY',
	'runtime_candle_exchange_name' => "VARCHAR(50) NOT NULL",
	'runtime_candle_ticker' => "VARCHAR(20) NOT NULL",
	'runtime_candle_market_type' => "ENUM('spot', 'futures') NOT NULL",
	'runtime_candle_timeframe' => "VARCHAR(10) NOT NULL",
	'runtime_candle_open_time' => "INT UNSIGNED NOT NULL",
	'runtime_candle_open' => "DECIMAL(20,8) NOT NULL",
	'runtime_candle_high' => "DECIMAL(20,8) NOT NULL",
	'runtime_candle_low' => "DECIMAL(20,8) NOT NULL",
	'runtime_candle_close' => "DECIMAL(20,8) NOT NULL",
	'runtime_candle_volume' => "DECIMAL(20,8) NOT NULL",
];
$keys = [
	'UNIQUE KEY uk_runtime_candles_series' => [
		'runtime_candle_exchange_name',
		'runtime_candle_ticker',
		'runtime_candle_market_type',
		'runtime_candle_timeframe',
		'runtime_candle_open_time',
	],
	'INDEX idx_runtime_candles_series_time' => [
		'runtime_candle_exchange_name',
		'runtime_candle_ticker',
		'runtime_candle_market_type',
		'runtime_candle_timeframe',
		'runtime_candle_open_time',
	],
];
$manager->createTable('runtime_candles', $fields, $keys);
