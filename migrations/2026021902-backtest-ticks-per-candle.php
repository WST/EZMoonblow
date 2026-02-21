<?php

/**
 * Migration: Add ticks_per_candle column to backtest_results.
 */

$manager->addTableColumn(
	'backtest_results',
	'br_ticks_per_candle',
	"SMALLINT UNSIGNED NOT NULL DEFAULT 4 AFTER br_mode",
);
