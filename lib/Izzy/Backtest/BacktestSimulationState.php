<?php

namespace Izzy\Backtest;

use Izzy\Financial\Candle;

/**
 * Intermediate state returned by BacktestEngine::runSimulation().
 * Carries all data needed by collectResults() to build the final BacktestResult.
 */
readonly class BacktestSimulationState
{
	/**
	 * @param bool $liquidated Whether the simulation ended in liquidation.
	 * @param float $maxDrawdown Peak-to-trough equity drawdown (negative value).
	 * @param float $peakEquity Highest equity value reached during simulation.
	 * @param int $peakEquityTime Timestamp of peak equity.
	 * @param int $longestLosingDuration Longest peak-to-recovery period in seconds.
	 * @param array $balanceSnapshots Array of [timestamp, equity] pairs per candle.
	 * @param Candle|null $lastCandle Last candle processed.
	 * @param int $candleDuration Candle duration in seconds.
	 * @param float $totalFees Total exchange fees paid.
	 * @param float $indicatorTimeNs Nanoseconds spent calculating indicators.
	 * @param bool $aborted Whether the simulation was aborted by user.
	 */
	public function __construct(
		public bool $liquidated,
		public float $maxDrawdown,
		public float $peakEquity,
		public int $peakEquityTime,
		public int $longestLosingDuration,
		public array $balanceSnapshots,
		public ?Candle $lastCandle,
		public int $candleDuration,
		public float $totalFees,
		public float $indicatorTimeNs = 0.0,
		public bool $aborted = false,
	) {
	}
}
