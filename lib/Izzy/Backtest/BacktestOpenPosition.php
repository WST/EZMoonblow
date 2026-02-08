<?php

namespace Izzy\Backtest;

/**
 * DTO for a single open/pending position at the end of a backtest.
 */
readonly class BacktestOpenPosition
{
	public function __construct(
		public string $direction,
		public float $entry,
		public float $volume,
		public int $createdAt,
		public float $unrealizedPnl,
		public int $timeHangingSec,
	) {
	}
}
