<?php

namespace Izzy\Strategies;

use Izzy\Enums\TimeFrameEnum;
use Izzy\Indicators\BollingerBands;
use Izzy\Indicators\EMA;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IMarket;
use Izzy\System\Logger;

/**
 * Single-entry mean-reversion strategy using Bollinger Bands.
 *
 * The core idea is the same as EZMoonblowSE: determine trend direction
 * from the daily timeframe, then enter at a local extreme on the working
 * timeframe. Instead of RSI, this strategy uses Bollinger Bands to
 * identify the extreme — price touching the outer band in the direction
 * opposite to the trend signals a mean-reversion opportunity.
 *
 * Bollinger Bands naturally adapt to volatility: they widen in volatile
 * markets (raising the bar for a "touch") and narrow in calm markets
 * (making touches more frequent). This self-adjusting behavior is a
 * good fit for ranging / sideways-trending instruments.
 *
 * Long entry conditions:
 *   - Daily close > EMA(slow) on 1D (uptrend territory)
 *   - Price touches or crosses below the lower Bollinger Band on the
 *     working timeframe (dip detected — entering at a local valley)
 *
 * Short entry conditions:
 *   - Daily close < EMA(slow) on 1D (downtrend territory)
 *   - Price touches or crosses above the upper Bollinger Band on the
 *     working timeframe (bounce detected — entering at a local peak)
 */
class EZMoonblowSEBoll extends AbstractSingleEntryStrategy
{
	/** How many daily candles to request for EMA calculation. */
	private const int DAILY_CANDLES_COUNT = 250;

	/** Slow EMA period for the daily trend filter. */
	private int $emaSlowPeriod;

	/** Bollinger Bands period (SMA window). */
	private int $bbPeriod;

	/** Bollinger Bands standard-deviation multiplier. */
	private float $bbMultiplier;

	/** Minimum candles to wait between entries (prevents whipsaw). */
	private int $cooldownCandles;

	/** Candle open-time of the last entry signal (cooldown tracker). */
	private int $lastEntryTime = 0;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->emaSlowPeriod = (int)($params['emaSlowPeriod'] ?? 50);
		$this->bbPeriod = (int)($params['bbPeriod'] ?? 20);
		$this->bbMultiplier = (float)($params['bbMultiplier'] ?? 2.0);
		$this->cooldownCandles = (int)($params['cooldownCandles'] ?? 6);
	}

	/**
	 * @inheritDoc
	 */
	public function useIndicators(): array {
		return [BollingerBands::class];
	}

	/**
	 * @inheritDoc
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

		if (!$this->cooldownElapsed()) {
			return false;
		}

		// 1. Daily trend filter: price above EMA(slow) = uptrend.
		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			$log->debug("[BOLL-LONG] No daily candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaSlow)) {
			$log->debug("[BOLL-LONG] EMA array empty");
			return false;
		}

		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		if ($latestDailyClose <= $latestEmaSlow) {
			$log->debug(sprintf(
				"[BOLL-LONG] Trend filter FAIL: close=%.8f <= EMA(%d)=%.8f",
				$latestDailyClose, $this->emaSlowPeriod, $latestEmaSlow,
			));
			return false;
		}

		// 2. Bollinger Band touch: price crosses below the lower band.
		if (!$this->priceCrossesBelowLowerBand()) {
			return false;
		}

		$this->markEntry();
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function shouldShort(): bool {
		$log = Logger::getLogger();

		if (!$this->cooldownElapsed()) {
			return false;
		}

		// 1. Daily trend filter: price below EMA(slow) = downtrend.
		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			$log->debug("[BOLL-SHORT] No daily candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaSlow)) {
			$log->debug("[BOLL-SHORT] EMA array empty");
			return false;
		}

		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		if ($latestDailyClose >= $latestEmaSlow) {
			$log->debug(sprintf(
				"[BOLL-SHORT] Trend filter FAIL: close=%.8f >= EMA(%d)=%.8f",
				$latestDailyClose, $this->emaSlowPeriod, $latestEmaSlow,
			));
			return false;
		}

		// 2. Bollinger Band touch: price crosses above the upper band.
		if (!$this->priceCrossesAboveUpperBand()) {
			return false;
		}

		$this->markEntry();
		return true;
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
	// Bollinger Bands crossover detection
	// ------------------------------------------------------------------

	/**
	 * Detect price crossing below the lower Bollinger Band.
	 *
	 * Uses a crossover approach (previous candle was above, current is at/below)
	 * to fire exactly once per touch, avoiding repeated entries while price
	 * stays below the band.
	 *
	 * @return bool True if price just crossed below the lower band.
	 */
	private function priceCrossesBelowLowerBand(): bool {
		$log = Logger::getLogger();
		$result = $this->market->getIndicatorResult(BollingerBands::getName());
		if ($result === null) {
			$log->debug("[BB↓] No Bollinger Bands data available");
			return false;
		}

		$bands = $result->getSignals(); // array of ['upper' => float, 'lower' => float]
		$count = count($bands);
		if ($count < 2) {
			$log->debug("[BB↓] Not enough BB values (count=$count)");
			return false;
		}

		$candles = $this->market->getCandles();
		$candleCount = count($candles);
		if ($candleCount < 2) {
			return false;
		}

		// The BB array is aligned to candles starting from index (period-1).
		// The last BB value corresponds to the last candle.
		$prevClose = $candles[$candleCount - 2]->getClosePrice();
		$currClose = $candles[$candleCount - 1]->getClosePrice();
		$prevLower = $bands[$count - 2]['lower'];
		$currLower = $bands[$count - 1]['lower'];

		$cross = $prevClose > $prevLower && $currClose <= $currLower;

		$log->debug(sprintf(
			"[BB↓] prevClose=%.8f prevLower=%.8f | currClose=%.8f currLower=%.8f | cross=%s",
			$prevClose, $prevLower, $currClose, $currLower,
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	/**
	 * Detect price crossing above the upper Bollinger Band.
	 *
	 * @return bool True if price just crossed above the upper band.
	 */
	private function priceCrossesAboveUpperBand(): bool {
		$log = Logger::getLogger();
		$result = $this->market->getIndicatorResult(BollingerBands::getName());
		if ($result === null) {
			$log->debug("[BB↑] No Bollinger Bands data available");
			return false;
		}

		$bands = $result->getSignals();
		$count = count($bands);
		if ($count < 2) {
			$log->debug("[BB↑] Not enough BB values (count=$count)");
			return false;
		}

		$candles = $this->market->getCandles();
		$candleCount = count($candles);
		if ($candleCount < 2) {
			return false;
		}

		$prevClose = $candles[$candleCount - 2]->getClosePrice();
		$currClose = $candles[$candleCount - 1]->getClosePrice();
		$prevUpper = $bands[$count - 2]['upper'];
		$currUpper = $bands[$count - 1]['upper'];

		$cross = $prevClose < $prevUpper && $currClose >= $currUpper;

		$log->debug(sprintf(
			"[BB↑] prevClose=%.8f prevUpper=%.8f | currClose=%.8f currUpper=%.8f | cross=%s",
			$prevClose, $prevUpper, $currClose, $currUpper,
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	// ------------------------------------------------------------------
	// Cooldown logic
	// ------------------------------------------------------------------

	/**
	 * Check if enough time has passed since the last entry.
	 */
	private function cooldownElapsed(): bool {
		if ($this->cooldownCandles <= 0) {
			return true;
		}
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return true;
		}
		$currentTime = (int)end($candles)->getOpenTime();
		$cooldownSeconds = $this->cooldownCandles * $this->market->getPair()->getTimeframe()->toSeconds();
		return $this->lastEntryTime === 0
			|| ($currentTime - $this->lastEntryTime) >= $cooldownSeconds;
	}

	/**
	 * Record the current candle time as the last entry moment.
	 */
	private function markEntry(): void {
		$candles = $this->market->getCandles();
		if (!empty($candles)) {
			$this->lastEntryTime = (int)end($candles)->getOpenTime();
		}
	}

	// ------------------------------------------------------------------
	// Multi-timeframe helpers
	// ------------------------------------------------------------------

	/**
	 * Request daily candles for EMA calculation.
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
		return $this->market->requestCandles(TimeFrameEnum::TF_1DAY, $startTime, $endTime);
	}

	// ------------------------------------------------------------------
	// Display
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function formatParameterName(string $paramName): string {
		$names = [
			'emaSlowPeriod' => 'EMA trend filter period (1D)',
			'bbPeriod' => 'Bollinger Bands period',
			'bbMultiplier' => 'Bollinger Bands StdDev multiplier',
			'cooldownCandles' => 'Cooldown between entries (candles)',
		];
		return $names[$paramName] ?? parent::formatParameterName($paramName);
	}
}
