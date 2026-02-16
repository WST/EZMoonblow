<?php

namespace Izzy\Strategies\EZMoonblowSEBoll;

use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Indicators\BollingerBands;
use Izzy\Indicators\EMA;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IMarket;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBMultiplier;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBPeriod;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\CooldownCandles;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\EMASlowPeriod;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\EMATrendFilter;
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
 *   - (optional) Daily close > EMA(slow) on 1D (uptrend territory)
 *   - Price touches or crosses below the lower Bollinger Band on the
 *     working timeframe (dip detected — entering at a local valley)
 *
 * Short entry conditions:
 *   - (optional) Daily close < EMA(slow) on 1D (downtrend territory)
 *   - Price touches or crosses above the upper Bollinger Band on the
 *     working timeframe (bounce detected — entering at a local peak)
 *
 * The EMA trend filter can be disabled via the emaTrendFilter parameter.
 * When disabled, the strategy enters on Bollinger Band touches in both
 * directions regardless of the daily trend, and daily candles are not
 * loaded at all (saving DB/API queries in backtests and live trading).
 */
class EZMoonblowSEBoll extends AbstractSingleEntryStrategy
{
	/** How many daily candles to request for EMA calculation. */
	private const int DAILY_CANDLES_COUNT = 250;

	/** Whether to use the daily EMA trend filter. */
	private bool $emaTrendFilter;

	/** Slow EMA period for the daily trend filter (only used when emaTrendFilter is enabled). */
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
		$this->emaTrendFilter = in_array(strtolower($params['emaTrendFilter'] ?? 'yes'), ['yes', 'true', '1'], true);
		$this->emaSlowPeriod = (int)($params['emaSlowPeriod'] ?? 50);
		$this->bbPeriod = (int)($params['bbPeriod'] ?? 20);
		$this->bbMultiplier = (float)($params['bbMultiplier'] ?? 2.0);
		$this->cooldownCandles = (int)($params['cooldownCandles'] ?? 6);
	}

	/**
	 * @inheritDoc
	 *
	 * Bollinger Bands are calculated directly via calculateFromPrices()
	 * to use strategy-specific period/multiplier parameters. No need
	 * to register them through the indicator system.
	 */
	public function useIndicators(): array {
		return [];
	}

	/**
	 * Timeframes needed beyond the market's native timeframe.
	 *
	 * Always declares 1D so that the backtester preloads daily candles into DB.
	 * This is a one-time cost during initialization. At runtime, daily candles
	 * are only fetched when emaTrendFilter is enabled.
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
		if (!$this->cooldownElapsed()) {
			return false;
		}

		// Optional daily EMA trend filter: only allow longs in an uptrend.
		if ($this->emaTrendFilter && !$this->dailyTrendIsUp()) {
			return false;
		}

		// Bollinger Band touch: price crosses below the lower band.
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
		if (!$this->cooldownElapsed()) {
			return false;
		}

		// Optional daily EMA trend filter: only allow shorts in a downtrend.
		if ($this->emaTrendFilter && !$this->dailyTrendIsDown()) {
			return false;
		}

		// Bollinger Band touch: price crosses above the upper band.
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
	// Daily EMA trend filter
	// ------------------------------------------------------------------

	/**
	 * Check if the daily trend is up (close > EMA slow).
	 * Only called when emaTrendFilter is enabled.
	 */
	private function dailyTrendIsUp(): bool {
		$log = Logger::getLogger();
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

		return true;
	}

	/**
	 * Check if the daily trend is down (close < EMA slow).
	 * Only called when emaTrendFilter is enabled.
	 */
	private function dailyTrendIsDown(): bool {
		$log = Logger::getLogger();
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

		return true;
	}

	// ------------------------------------------------------------------
	// Bollinger Bands crossover detection
	// ------------------------------------------------------------------

	/**
	 * Calculate Bollinger Bands from market candles using strategy parameters.
	 *
	 * @return array{bands: array<array{upper: float, lower: float}>, closePrices: float[]}|null
	 */
	private function calculateBB(): ?array {
		$candles = $this->market->getCandles();
		if (count($candles) < $this->bbPeriod) {
			return null;
		}
		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);
		$result = BollingerBands::calculateFromPrices($closePrices, $this->bbPeriod, $this->bbMultiplier);
		if (count($result['bands']) < 2) {
			return null;
		}
		return ['bands' => $result['bands'], 'closePrices' => $closePrices];
	}

	/**
	 * Detect price crossing below the lower Bollinger Band.
	 *
	 * @return bool True if price just crossed below the lower band.
	 */
	private function priceCrossesBelowLowerBand(): bool {
		$log = Logger::getLogger();
		$bb = $this->calculateBB();
		if ($bb === null) {
			$log->debug("[BB↓] Not enough data for Bollinger Bands");
			return false;
		}

		$bands = $bb['bands'];
		$prices = $bb['closePrices'];
		$count = count($bands);
		$priceCount = count($prices);

		// BB array is shorter than price array by (period-1).
		// Last BB value corresponds to the last close price.
		$prevClose = $prices[$priceCount - 2];
		$currClose = $prices[$priceCount - 1];
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
		$bb = $this->calculateBB();
		if ($bb === null) {
			$log->debug("[BB↑] Not enough data for Bollinger Bands");
			return false;
		}

		$bands = $bb['bands'];
		$prices = $bb['closePrices'];
		$count = count($bands);
		$priceCount = count($prices);

		$prevClose = $prices[$priceCount - 2];
		$currClose = $prices[$priceCount - 1];
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
	// Parameter definitions
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function getParameters(): array {
		return array_merge(parent::getParameters(), [
			new EMATrendFilter(),
			new EMASlowPeriod(),
			new BBPeriod(),
			new BBMultiplier(),
			new CooldownCandles(),
		]);
	}
}
