<?php

/**
 * Migration: Create backtest_results table.
 *
 * Stores the full summary of every completed backtest run (both console and web)
 * so that users can review historical results from the web UI.
 */
$fields = [
	'br_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
	'br_exchange_name' => "VARCHAR(50) NOT NULL",
	'br_ticker' => "VARCHAR(20) NOT NULL",
	'br_market_type' => "ENUM('spot', 'futures') NOT NULL",
	'br_timeframe' => "VARCHAR(10) NOT NULL",
	'br_strategy' => "VARCHAR(100) NOT NULL",
	'br_strategy_params' => "JSON NULL DEFAULT NULL",
	'br_initial_balance' => "DECIMAL(20,8) NOT NULL",
	'br_final_balance' => "DECIMAL(20,8) NOT NULL",
	'br_pnl' => "DECIMAL(20,8) NOT NULL",
	'br_pnl_percent' => "DECIMAL(10,4) NOT NULL",
	'br_max_drawdown' => "DECIMAL(20,8) NOT NULL",
	'br_liquidated' => "TINYINT(1) NOT NULL DEFAULT 0",
	'br_coin_price_start' => "DECIMAL(20,8) NOT NULL DEFAULT 0",
	'br_coin_price_end' => "DECIMAL(20,8) NOT NULL DEFAULT 0",
	'br_trades_finished' => "INT NOT NULL DEFAULT 0",
	'br_trades_open' => "INT NOT NULL DEFAULT 0",
	'br_trades_pending' => "INT NOT NULL DEFAULT 0",
	'br_trades_wins' => "INT NOT NULL DEFAULT 0",
	'br_trades_losses' => "INT NOT NULL DEFAULT 0",
	'br_trade_shortest' => "INT NOT NULL DEFAULT 0",
	'br_trade_longest' => "INT NOT NULL DEFAULT 0",
	'br_trade_average' => "INT NOT NULL DEFAULT 0",
	'br_trade_idle' => "INT NOT NULL DEFAULT 0",
	'br_sharpe' => "DECIMAL(10,4) NULL DEFAULT NULL",
	'br_sortino' => "DECIMAL(10,4) NULL DEFAULT NULL",
	'br_avg_return' => "DECIMAL(16,8) NULL DEFAULT NULL",
	'br_std_deviation' => "DECIMAL(16,8) NULL DEFAULT NULL",
	'br_long_finished' => "INT NOT NULL DEFAULT 0",
	'br_long_wins' => "INT NOT NULL DEFAULT 0",
	'br_long_losses' => "INT NOT NULL DEFAULT 0",
	'br_long_bl' => "INT NOT NULL DEFAULT 0",
	'br_long_shortest' => "INT NOT NULL DEFAULT 0",
	'br_long_longest' => "INT NOT NULL DEFAULT 0",
	'br_long_average' => "INT NOT NULL DEFAULT 0",
	'br_short_finished' => "INT NOT NULL DEFAULT 0",
	'br_short_wins' => "INT NOT NULL DEFAULT 0",
	'br_short_losses' => "INT NOT NULL DEFAULT 0",
	'br_short_bl' => "INT NOT NULL DEFAULT 0",
	'br_short_shortest' => "INT NOT NULL DEFAULT 0",
	'br_short_longest' => "INT NOT NULL DEFAULT 0",
	'br_short_average' => "INT NOT NULL DEFAULT 0",
	'br_sim_start' => "INT UNSIGNED NOT NULL",
	'br_sim_end' => "INT UNSIGNED NOT NULL",
	'br_created_at' => "INT UNSIGNED NOT NULL",
	'br_open_positions' => "JSON NULL DEFAULT NULL",
];
$keys = [
	'PRIMARY KEY' => ['br_id'],
	'INDEX idx_br_created' => ['br_created_at'],
	'INDEX idx_br_ticker_strategy' => ['br_ticker', 'br_strategy'],
];
$manager->createTable('backtest_results', $fields, $keys);
