<?php

/**
 * Migration: Add total fees column to backtest_results.
 */

$manager->addTableColumn(
	'backtest_results',
	'br_total_fees',
	"DECIMAL(16,8) NOT NULL DEFAULT 0 AFTER br_coin_price_end",
);
