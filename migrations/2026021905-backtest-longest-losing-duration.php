<?php

/**
 * Migration: Add longest losing duration column to backtest_results.
 */

$manager->addTableColumn(
	'backtest_results',
	'br_longest_losing_duration',
	"INT UNSIGNED NOT NULL DEFAULT 0 AFTER br_ticks_per_candle",
);
