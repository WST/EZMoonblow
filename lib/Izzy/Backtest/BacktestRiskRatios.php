<?php

namespace Izzy\Backtest;

use Izzy\Traits\ConsoleTableTrait;
use Stringable;

/**
 * DTO for the "Risk Ratios" section of the backtest summary.
 * Contains Sharpe ratio, Sortino ratio, average return, and standard deviation.
 */
readonly class BacktestRiskRatios implements Stringable
{
	use ConsoleTableTrait;

	private const int MIN_TRADES = 5;

	public function __construct(
		public ?float $sharpe,
		public ?float $sortino,
		public float $avgReturn,
		public float $stdDeviation,
	) {
	}

	/**
	 * Build risk ratios from per-trade PnL data.
	 *
	 * Returns null if fewer than MIN_TRADES trades are available (not enough data).
	 *
	 * @param float[] $tradePnls Array of PnL values for each finished trade.
	 * @param float $initialBalance Starting balance for return calculation.
	 * @param int $totalTrades Total number of finished trades.
	 * @param float $simDurationDays Duration of simulation in days.
	 * @return self|null
	 */
	public static function fromTradePnls(
		array $tradePnls,
		float $initialBalance,
		int $totalTrades,
		float $simDurationDays,
	): ?self {
		if (count($tradePnls) < self::MIN_TRADES || $initialBalance <= 0) {
			return null;
		}

		$returns = array_map(fn(float $p) => $p / $initialBalance, $tradePnls);
		$meanReturn = array_sum($returns) / count($returns);

		// Standard deviation (for Sharpe).
		$squaredDiffs = array_map(fn(float $r) => ($r - $meanReturn) ** 2, $returns);
		$stdDev = sqrt(array_sum($squaredDiffs) / count($returns));

		// Downside deviation (for Sortino): only negative deviations from mean.
		$downsideSquared = array_map(
			fn(float $r) => $r < $meanReturn ? ($r - $meanReturn) ** 2 : 0.0,
			$returns
		);
		$downsideDev = sqrt(array_sum($downsideSquared) / count($returns));

		// Annualize: scale by sqrt(trades_per_year).
		$tradesPerYear = $simDurationDays > 0
			? ($totalTrades / $simDurationDays) * 365
			: $totalTrades;
		$annualizationFactor = sqrt($tradesPerYear);

		$sharpe = $stdDev > 0 ? ($meanReturn / $stdDev) * $annualizationFactor : null;
		$sortino = $downsideDev > 0 ? ($meanReturn / $downsideDev) * $annualizationFactor : null;

		// Guard against INF/NaN from near-zero denominators.
		if ($sharpe !== null && !is_finite($sharpe)) {
			$sharpe = null;
		}
		if ($sortino !== null && !is_finite($sortino)) {
			$sortino = null;
		}

		return new self(
			sharpe: $sharpe,
			sortino: $sortino,
			avgReturn: is_finite($meanReturn) ? $meanReturn : 0.0,
			stdDeviation: is_finite($stdDev) ? $stdDev : 0.0,
		);
	}

	public function __toString(): string {
		$h = ['Metric', 'Value'];
		$rows = [
			['Sharpe Ratio', $this->sharpe !== null ? number_format($this->sharpe, 2) : 'N/A (zero volatility)'],
			['Sortino Ratio', $this->sortino !== null ? number_format($this->sortino, 2) : 'N/A (no downside)'],
			['Avg return/trade', number_format($this->avgReturn * 100, 4) . '%'],
			['Std deviation', number_format($this->stdDeviation * 100, 4) . '%'],
		];
		return $this->renderTable('Risk Ratios', $h, $rows);
	}
}
