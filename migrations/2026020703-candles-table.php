<?php

/**
 * Migration: Create candles table for backtesting.
 *
 * Stores OHLCV candle history per exchange/ticker/market_type/timeframe
 * for reuse in backtest runs.
 */
$candlesFields = [
	'candle_id' => 'INT AUTO_INCREMENT PRIMARY KEY',
	'candle_exchange_name' => "VARCHAR(50) NOT NULL",
	'candle_ticker' => "VARCHAR(20) NOT NULL",
	'candle_market_type' => "ENUM('spot', 'futures') NOT NULL",
	'candle_timeframe' => "VARCHAR(10) NOT NULL",
	'candle_open_time' => "INT UNSIGNED NOT NULL",
	'candle_open' => "DECIMAL(20,8) NOT NULL",
	'candle_high' => "DECIMAL(20,8) NOT NULL",
	'candle_low' => "DECIMAL(20,8) NOT NULL",
	'candle_close' => "DECIMAL(20,8) NOT NULL",
	'candle_volume' => "DECIMAL(20,8) NOT NULL",
];
$candlesKeys = [
	'UNIQUE KEY uk_candles_series' => [
		'candle_exchange_name',
		'candle_ticker',
		'candle_market_type',
		'candle_timeframe',
		'candle_open_time',
	],
	'INDEX idx_candles_series_time' => ['candle_exchange_name', 'candle_ticker', 'candle_market_type', 'candle_timeframe', 'candle_open_time'],
];
$manager->createTable('candles', $candlesFields, $candlesKeys);
