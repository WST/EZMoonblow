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

	public function __construct(
		public float $initialBalance,
		public float $finalBalance,
		public float $maxDrawdown,
		public bool $liquidated,
	) {
	}

	public function getPnl(): float
	{
		return $this->finalBalance - $this->initialBalance;
	}

	public function getPnlPercent(): float
	{
		return $this->initialBalance > 0
			? ($this->getPnl() / $this->initialBalance) * 100
			: 0.0;
	}

	public function __toString(): string
	{
		$pnl = $this->getPnl();
		$pnlPercent = $this->getPnlPercent();
		$h = ['Metric', 'Value'];

		$drawdownStr = number_format($this->maxDrawdown, 2) . ' USDT';
		if ($this->initialBalance > 0 && abs($this->maxDrawdown) > 0) {
			$drawdownPercent = ($this->maxDrawdown / $this->initialBalance) * 100;
			$drawdownStr .= ' (' . number_format($drawdownPercent, 2) . '%)';
		}

		return $this->renderTable('Financial', $h, [
			['Initial balance', number_format($this->initialBalance, 2) . ' USDT'],
			['Final balance', number_format($this->finalBalance, 2) . ' USDT'],
			['PnL', number_format($pnl, 2) . ' USDT (' . number_format($pnlPercent, 2) . '%)'],
			['Max drawdown', $drawdownStr],
		]);
	}
}
