<?php

/**
 * Migration: Rename candles table to backtest_candles for naming consistency
 * with market_candles and runtime_candles tables.
 *
 * Renames table and all columns from candle_* prefix to backtest_candle_*.
 */

$oldPrefix = 'candle_';
$newPrefix = 'backtest_candle_';

$manager->renameTable('candles', 'backtest_candles');

$columns = $manager->getTableColumns('backtest_candles');
foreach ($columns as $column) {
	if (str_starts_with($column, $oldPrefix)) {
		$newName = $newPrefix . substr($column, strlen($oldPrefix));
		$manager->renameColumn('backtest_candles', $column, $newName);
	}
}

$manager->dropIndex('backtest_candles', 'uk_candles_series');
$manager->dropIndex('backtest_candles', 'idx_candles_series_time');

$manager->addIndex('backtest_candles', 'UNIQUE KEY uk_backtest_candles_series (backtest_candle_exchange_name, backtest_candle_ticker, backtest_candle_market_type, backtest_candle_timeframe, backtest_candle_open_time)');
$manager->addIndex('backtest_candles', 'INDEX idx_backtest_candles_series_time (backtest_candle_exchange_name, backtest_candle_ticker, backtest_candle_market_type, backtest_candle_timeframe, backtest_candle_open_time)');
