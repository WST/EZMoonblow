<?php

/**
 * Migration: Widen DECIMAL columns in backtest_results to prevent overflow.
 *
 * br_pnl_percent: DECIMAL(10,4) → DECIMAL(20,4) to handle extreme compounding profits.
 * br_sharpe/br_sortino: DECIMAL(10,4) → DECIMAL(16,4) to handle edge cases.
 */

$manager->modifyTableColumn(
	'backtest_results',
	'br_pnl_percent',
	"DECIMAL(20,4) NOT NULL",
);

$manager->modifyTableColumn(
	'backtest_results',
	'br_sharpe',
	"DECIMAL(16,4) NULL DEFAULT NULL",
);

$manager->modifyTableColumn(
	'backtest_results',
	'br_sortino',
	"DECIMAL(16,4) NULL DEFAULT NULL",
);
