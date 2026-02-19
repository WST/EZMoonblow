<?php

/**
 * Migration: Add Optimizer support.
 *
 * 1. Add br_mode column to backtest_results (Manual/Auto).
 * 2. Create optimization_suggestions table.
 * 3. Extend task_recipient ENUM with 'Optimizer'.
 */

// 1. Mark backtests as Manual (default) or Auto (created by Optimizer).
$manager->addTableColumn(
	'backtest_results',
	'br_mode',
	"ENUM('Manual','Auto') NOT NULL DEFAULT 'Manual' AFTER br_balance_chart",
);

// 2. Store optimization suggestions when a mutated parameter improves PnL.
$fields = [
	'os_id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
	'os_ticker' => "VARCHAR(20) NOT NULL",
	'os_exchange_name' => "VARCHAR(50) NOT NULL",
	'os_market_type' => "ENUM('spot','futures') NOT NULL",
	'os_timeframe' => "VARCHAR(10) NOT NULL",
	'os_strategy' => "VARCHAR(100) NOT NULL",
	'os_mutated_param' => "VARCHAR(64) NOT NULL",
	'os_original_value' => "VARCHAR(32) NOT NULL",
	'os_mutated_value' => "VARCHAR(32) NOT NULL",
	'os_baseline_pnl_percent' => "DECIMAL(20,4) NOT NULL",
	'os_mutated_pnl_percent' => "DECIMAL(20,4) NOT NULL",
	'os_improvement_percent' => "DECIMAL(20,4) NOT NULL",
	'os_baseline_backtest_id' => "INT UNSIGNED NOT NULL",
	'os_mutated_backtest_id' => "INT UNSIGNED NOT NULL",
	'os_suggested_xml' => "TEXT NULL DEFAULT NULL",
	'os_status' => "ENUM('New','Applied','Dismissed') NOT NULL DEFAULT 'New'",
	'os_created_at' => "INT UNSIGNED NOT NULL",
];
$keys = [
	'PRIMARY KEY' => ['os_id'],
	'INDEX idx_os_created' => ['os_created_at'],
	'INDEX idx_os_ticker' => ['os_ticker'],
];
$manager->createTable('optimization_suggestions', $fields, $keys);

// 3. Allow task queue to route messages to Optimizer.
$manager->modifyTableColumn(
	'tasks',
	'task_recipient',
	"ENUM('Trader','Analyzer','Notifier','Optimizer') NOT NULL",
);
