<?php

namespace Izzy\Strategies;

use Izzy\Enums\TimeFrameEnum;
use Izzy\Indicators\EMA;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\IMarket;

/**
 * Single-entry strategy that combines a daily EMA trend filter
 * with hourly RSI crossover signals.
 *
 * Long entry conditions:
 *   - EMA(fast) > EMA(slow) on 1D (uptrend)
 *   - Daily close > EMA(fast) on 1D
 *   - RSI on 1H crosses above the oversold threshold
 *
 * Short entry conditions:
 *   - EMA(fast) < EMA(slow) on 1D (downtrend)
 *   - Daily close < EMA(fast) on 1D
 *   - RSI on 1H crosses below the overbought threshold
 */
class EZMoonblowSE extends AbstractSingleEntryStrategy
{
	/** How many daily candles to request for EMA calculation. */
	private const int DAILY_CANDLES_COUNT = 250;

	/** Fast EMA period for the daily trend filter. */
	private int $emaFastPeriod;

	/** Slow EMA period for the daily trend filter. */
	private int $emaSlowPeriod;

	/** RSI oversold threshold. */
	private float $rsiOversold;

	/** RSI overbought threshold. */
	private float $rsiOverbought;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->emaFastPeriod = (int)($params['emaFastPeriod'] ?? 50);
		$this->emaSlowPeriod = (int)($params['emaSlowPeriod'] ?? 200);
		$this->rsiOversold = (float)($params['rsiOversold'] ?? 30);
		$this->rsiOverbought = (float)($params['rsiOverbought'] ?? 70);
	}

	/**
	 * @inheritDoc
	 */
	public function useIndicators(): array {
		return [RSI::class];
	}

	/**
	 * Timeframes needed beyond the market's native timeframe.
	 * Used by the backtester to pre-load historical candles.
	 *
	 * @return TimeFrameEnum[]
	 */
	public static function requiredTimeframes(): array {
		return [TimeFrameEnum::TF_1DAY];
	}

	// ------------------------------------------------------------------
	// Entry signal detection
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public function shouldLong(): bool {
		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaFast = EMA::calculateFromPrices($closePrices, $this->emaFastPeriod);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaFast) || empty($emaSlow)) {
			return false;
		}

		$latestEmaFast = end($emaFast);
		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		// Trend filter: EMA(fast) > EMA(slow) AND close above EMA(fast).
		if ($latestEmaFast <= $latestEmaSlow) {
			return false;
		}
		if ($latestDailyClose <= $latestEmaFast) {
			return false;
		}

		return $this->rsiCrossesAbove($this->rsiOversold);
	}

	/**
	 * @inheritDoc
	 */
	public function shouldShort(): bool {
		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaFast = EMA::calculateFromPrices($closePrices, $this->emaFastPeriod);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaFast) || empty($emaSlow)) {
			return false;
		}

		$latestEmaFast = end($emaFast);
		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		// Trend filter: EMA(fast) < EMA(slow) AND close below EMA(fast).
		if ($latestEmaFast >= $latestEmaSlow) {
			return false;
		}
		if ($latestDailyClose >= $latestEmaFast) {
			return false;
		}

		return $this->rsiCrossesBelow($this->rsiOverbought);
	}

	/**
	 * @inheritDoc
	 */
	public function doesLong(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function doesShort(): bool {
		return true;
	}

	// ------------------------------------------------------------------
	// Multi-timeframe helpers
	// ------------------------------------------------------------------

	/**
	 * Request daily candles for EMA calculation.
	 * Uses lastCandle time instead of wall-clock time to avoid look-ahead bias in backtests.
	 *
	 * @return \Izzy\Interfaces\ICandle[]|null Array of daily candles or null if still loading.
	 */
	private function getDailyCandles(): ?array {
		$endTime = (int)$this->market->lastCandle()->getOpenTime();
		$startTime = $endTime - self::DAILY_CANDLES_COUNT * TimeFrameEnum::TF_1DAY->toSeconds();
		return $this->market->requestCandles(TimeFrameEnum::TF_1DAY, $startTime, $endTime);
	}

	// ------------------------------------------------------------------
	// RSI crossover detection
	// ------------------------------------------------------------------

	/**
	 * Check if RSI crosses above the given threshold.
	 * Previous RSI value must be below and current value must be at or above the threshold.
	 *
	 * @param float $threshold RSI threshold level.
	 * @return bool True if RSI crosses above the threshold.
	 */
	private function rsiCrossesAbove(float $threshold): bool {
		$result = $this->market->getIndicatorResult('RSI');
		if ($result === null) {
			return false;
		}
		$values = $result->getValues();
		$count = count($values);
		if ($count < 2) {
			return false;
		}
		$previous = $values[$count - 2];
		$current = $values[$count - 1];
		return $previous < $threshold && $current >= $threshold;
	}

	/**
	 * Check if RSI crosses below the given threshold.
	 * Previous RSI value must be above and current value must be at or below the threshold.
	 *
	 * @param float $threshold RSI threshold level.
	 * @return bool True if RSI crosses below the threshold.
	 */
	private function rsiCrossesBelow(float $threshold): bool {
		$result = $this->market->getIndicatorResult('RSI');
		if ($result === null) {
			return false;
		}
		$values = $result->getValues();
		$count = count($values);
		if ($count < 2) {
			return false;
		}
		$previous = $values[$count - 2];
		$current = $values[$count - 1];
		return $previous > $threshold && $current <= $threshold;
	}

	// ------------------------------------------------------------------
	// Display
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function formatParameterName(string $paramName): string {
		$names = [
			'emaFastPeriod' => 'EMA fast period (daily trend)',
			'emaSlowPeriod' => 'EMA slow period (daily trend)',
			'rsiOversold' => 'RSI oversold threshold',
			'rsiOverbought' => 'RSI overbought threshold',
		];
		return $names[$paramName] ?? parent::formatParameterName($paramName);
	}
}
