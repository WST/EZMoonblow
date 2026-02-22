<?php

namespace Izzy\Backtest;

use Izzy\Traits\ConsoleTableTrait;
use Stringable;

/**
 * DTO for the "Financial" section of the backtest summary.
 */
readonly class BacktestFinancialResult implements Stringable
{
	use ConsoleTableTrait;

	/**
	 * @param float $initialBalance Starting USDT balance.
	 * @param float $finalBalance Ending USDT balance.
	 * @param float $maxDrawdown Peak-to-trough equity drawdown (negative value).
	 * @param bool $liquidated Whether the simulation ended in liquidation.
	 * @param float $coinPriceStart Asset price at simulation start.
	 * @param float $coinPriceEnd Asset price at simulation end.
	 * @param float $totalFees Total exchange fees paid during simulation.
	 * @param int $longestLosingDuration Longest peak-to-recovery period in seconds.
	 */
	public function __construct(
		public float $initialBalance,
		public float $finalBalance,
		public float $maxDrawdown,
		public bool $liquidated,
		public float $coinPriceStart = 0.0,
		public float $coinPriceEnd = 0.0,
		public float $totalFees = 0.0,
		public int $longestLosingDuration = 0,
	) {
	}

	public function getPnl(): float {
		return $this->finalBalance - $this->initialBalance;
	}

	public function getPnlPercent(): float {
		return $this->initialBalance > 0
			? ($this->getPnl() / $this->initialBalance) * 100
			: 0.0;
	}

	private function formatDuration(int $seconds): string {
		if ($seconds <= 0) {
			return '0s';
		}
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);
		$parts = [];
		if ($days > 0) {
			$parts[] = $days . 'd';
		}
		if ($hours > 0) {
			$parts[] = $hours . 'h';
		}
		if ($minutes > 0) {
			$parts[] = $minutes . 'm';
		}
		return empty($parts) ? $seconds . 's' : implode(' ', $parts);
	}

	public function __toString(): string {
		$pnl = $this->getPnl();
		$pnlPercent = $this->getPnlPercent();
		$h = ['Metric', 'Value'];

		$drawdownStr = number_format($this->maxDrawdown, 2) . ' USDT';
		if ($this->initialBalance > 0 && abs($this->maxDrawdown) > 0) {
			$drawdownPercent = ($this->maxDrawdown / $this->initialBalance) * 100;
			$drawdownStr .= ' (' . number_format($drawdownPercent, 2) . '%)';
		}

		$losingStr = $this->formatDuration($this->longestLosingDuration);

		$rows = [
			['Initial balance', number_format($this->initialBalance, 2) . ' USDT'],
			['Final balance', number_format($this->finalBalance, 2) . ' USDT'],
			['PnL', number_format($pnl, 2) . ' USDT (' . number_format($pnlPercent, 2) . '%)'],
			['Total fees', number_format($this->totalFees, 2) . ' USDT'],
			['Max drawdown', $drawdownStr],
			['Longest losing period', $losingStr],
		];

		// Asset price change during the simulation period.
		if ($this->coinPriceStart > 0 && $this->coinPriceEnd > 0) {
			$priceChangePercent = (($this->coinPriceEnd - $this->coinPriceStart) / $this->coinPriceStart) * 100;
			$rows[] = [
				'Asset price',
				number_format($this->coinPriceStart, 4)
					. ' → ' . number_format($this->coinPriceEnd, 4)
					. ' (' . ($priceChangePercent >= 0 ? '+' : '') . number_format($priceChangePercent, 2) . '%)',
			];
		}

		return $this->renderTable('Financial', $h, $rows);
	}
}
