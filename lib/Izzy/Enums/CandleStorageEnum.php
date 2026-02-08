<?php

namespace Izzy\Enums;

/**
 * Target storage for candle loading tasks.
 */
enum CandleStorageEnum: string
{
	case BACKTEST = 'backtest';   // candles table (for backtesting).
	case RUNTIME = 'runtime';    // runtime_candles table (for indicators).

	/**
	 * Check if this is backtest storage.
	 */
	public function isBacktest(): bool {
		return $this === self::BACKTEST;
	}

	/**
	 * Check if this is runtime storage.
	 */
	public function isRuntime(): bool {
		return $this === self::RUNTIME;
	}
}
