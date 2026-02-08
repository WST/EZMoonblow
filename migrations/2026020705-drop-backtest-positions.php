<?php

/**
 * Migration: Drop backtest_positions if it exists.
 *
 * The backtest_positions table is now created dynamically at the start of
 * tasks/backtesting/run (as a copy of positions) and dropped when the run finishes.
 * This migration cleans up the table for installs that had it created by the removed
 * 2026020704-backtest-positions migration.
 */
$manager->dropTableIfExists('backtest_positions');
