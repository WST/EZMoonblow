<?php

namespace Izzy\Strategies;

use Izzy\Enums\TimeFrameEnum;
use Izzy\Indicators\EMA;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IMarket;
use Izzy\System\Logger;

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
		$log = Logger::getLogger();
		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			$log->debug("[LONG] No daily candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaFast = EMA::calculateFromPrices($closePrices, $this->emaFastPeriod);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaFast) || empty($emaSlow)) {
			$log->debug("[LONG] EMA arrays empty (fast=" . count($emaFast) . ", slow=" . count($emaSlow) . ")");
			return false;
		}

		$latestEmaFast = end($emaFast);
		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		$log->debug(sprintf(
			"[LONG] close=%.8f | EMA(%d)=%.8f | EMA(%d)=%.8f | fast>slow=%s | close>fast=%s",
			$latestDailyClose,
			$this->emaFastPeriod, $latestEmaFast,
			$this->emaSlowPeriod, $latestEmaSlow,
			$latestEmaFast > $latestEmaSlow ? 'YES' : 'NO',
			$latestDailyClose > $latestEmaFast ? 'YES' : 'NO',
		));

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
		$log = Logger::getLogger();
		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			$log->debug("[SHORT] No daily candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaFast = EMA::calculateFromPrices($closePrices, $this->emaFastPeriod);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaFast) || empty($emaSlow)) {
			$log->debug("[SHORT] EMA arrays empty (fast=" . count($emaFast) . ", slow=" . count($emaSlow) . ")");
			return false;
		}

		$latestEmaFast = end($emaFast);
		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		$log->debug(sprintf(
			"[SHORT] close=%.8f | EMA(%d)=%.8f | EMA(%d)=%.8f | fast<slow=%s | close<fast=%s",
			$latestDailyClose,
			$this->emaFastPeriod, $latestEmaFast,
			$this->emaSlowPeriod, $latestEmaSlow,
			$latestEmaFast < $latestEmaSlow ? 'YES' : 'NO',
			$latestDailyClose < $latestEmaFast ? 'YES' : 'NO',
		));

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
	 * @return ICandle[]|null Array of daily candles or null if still loading.
	 */
	private function getDailyCandles(): ?array {
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return null;
		}
		$endTime = (int)end($candles)->getOpenTime();
		$startTime = $endTime - self::DAILY_CANDLES_COUNT * TimeFrameEnum::TF_1DAY->toSeconds();
		$candles = $this->market->requestCandles(TimeFrameEnum::TF_1DAY, $startTime, $endTime);
		$count = $candles !== null ? count($candles) : 'null';
		$need = self::DAILY_CANDLES_COUNT;
		Logger::getLogger()->debug("[EMA] getDailyCandles: need=$need, got=$count, range=" . date('Y-m-d', $startTime) . " → " . date('Y-m-d', $endTime));
		return $candles;
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
		$log = Logger::getLogger();
		$result = $this->market->getIndicatorResult(RSI::getName());
		if (is_null($result)) {
			$log->debug("[RSI↑] No RSI data available");
			return false;
		}
		$values = $result->getValues();
		$count = count($values);
		if ($count < 2) {
			$log->debug("[RSI↑] Not enough RSI values (count=$count)");
			return false;
		}
		$previous = $values[$count - 2];
		$current = $values[$count - 1];
		$cross = $previous < $threshold && $current >= $threshold;
		$log->debug(sprintf(
			"[RSI↑] prev=%.2f → cur=%.2f | threshold=%.1f | cross=%s",
			$previous, $current, $threshold, $cross ? 'YES — SIGNAL!' : 'no'
		));
		return $cross;
	}

	/**
	 * Check if RSI crosses below the given threshold.
	 * Previous RSI value must be above and current value must be at or below the threshold.
	 *
	 * @param float $threshold RSI threshold level.
	 * @return bool True if RSI crosses below the threshold.
	 */
	private function rsiCrossesBelow(float $threshold): bool {
		$log = Logger::getLogger();
		$result = $this->market->getIndicatorResult(RSI::getName());
		if (is_null($result)) {
			$log->debug("[RSI↓] No RSI data available");
			return false;
		}
		$values = $result->getValues();
		$count = count($values);
		if ($count < 2) {
			$log->debug("[RSI↓] Not enough RSI values (count=$count)");
			return false;
		}
		$previous = $values[$count - 2];
		$current = $values[$count - 1];
		$cross = $previous > $threshold && $current <= $threshold;
		$log->debug(sprintf(
			"[RSI↓] prev=%.2f → cur=%.2f | threshold=%.1f | cross=%s",
			$previous, $current, $threshold, $cross ? 'YES — SIGNAL!' : 'no'
		));
		return $cross;
	}

	// ------------------------------------------------------------------
	// Display
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function formatParameterName(string $paramName): string {
		$names = [
			'emaFastPeriod' => 'EMA fast period (1D)',
			'emaSlowPeriod' => 'EMA slow period (1D)',
			'rsiOversold' => 'RSI oversold threshold (1H)',
			'rsiOverbought' => 'RSI overbought threshold (1H)',
		];
		return $names[$paramName] ?? parent::formatParameterName($paramName);
	}
}
