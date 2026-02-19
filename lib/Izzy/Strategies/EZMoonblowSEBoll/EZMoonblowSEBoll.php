<?php

namespace Izzy\Strategies\EZMoonblowSEBoll;

use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Indicators\BollingerBands;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\IMarket;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBMultiplier;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBOffset;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBPeriod;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBSlopeFilter;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBSlopeMax;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\BBSlopePeriod;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\CooldownCandles;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\RSINeutralFilter;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\RSINeutralHigh;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\RSINeutralLow;
use Izzy\Strategies\EZMoonblowSEBoll\Parameters\RSIPeriod;
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
	public static function getDisplayName(): string {
		return 'Bollinger Bands Single Entry';
	}

	/** Bollinger Bands period (SMA window). */
	private int $bbPeriod;

	/** Bollinger Bands standard-deviation multiplier. */
	private float $bbMultiplier;

	/** Percentage beyond the BB that price must penetrate before entry. */
	private float $bbOffset;

	/** Whether RSI neutral zone filter is enabled. */
	private bool $rsiNeutralFilter;

	/** RSI calculation period. */
	private int $rsiPeriod;

	/** Lower boundary of the RSI neutral zone. */
	private float $rsiNeutralLow;

	/** Upper boundary of the RSI neutral zone. */
	private float $rsiNeutralHigh;

	/** Whether the BB slope filter is enabled. */
	private bool $bbSlopeFilter;

	/** Number of candles to look back when measuring band slope. */
	private int $bbSlopePeriod;

	/** Maximum allowed absolute % change of the band over the lookback. */
	private float $bbSlopeMax;

	/** Minimum candles to wait between entries (prevents whipsaw). */
	private int $cooldownCandles;

	/** Candle open-time of the last entry signal (cooldown tracker). */
	private int $lastEntryTime = 0;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->bbPeriod = (int)($params['bbPeriod'] ?? 20);
		$this->bbMultiplier = (float)($params['bbMultiplier'] ?? 2.0);
		$this->bbOffset = (float)($params['bbOffset'] ?? 0);
		$this->rsiNeutralFilter = in_array(strtolower($params['rsiNeutralFilter'] ?? 'false'), ['yes', 'true', '1'], true);
		$this->rsiPeriod = (int)($params['rsiPeriod'] ?? 14);
		$this->rsiNeutralLow = (float)($params['rsiNeutralLow'] ?? 30);
		$this->rsiNeutralHigh = (float)($params['rsiNeutralHigh'] ?? 70);
		$this->bbSlopeFilter = in_array(strtolower($params['bbSlopeFilter'] ?? 'false'), ['yes', 'true', '1'], true);
		$this->bbSlopePeriod = max(2, (int)($params['bbSlopePeriod'] ?? 5));
		$this->bbSlopeMax = (float)($params['bbSlopeMax'] ?? 1.0);
		$this->cooldownCandles = (int)($params['cooldownCandles'] ?? 6);
	}

	/**
	 * @inheritDoc
	 *
	 * Register Bollinger Bands with the strategy's custom period/multiplier
	 * so the indicator system streams values to the chart overlay.
	 * The strategy still computes BB internally for crossing detection.
	 */
	public function useIndicators(): array {
		return [
			[
				'class' => BollingerBands::class,
				'parameters' => [
					'period' => $this->bbPeriod,
					'multiplier' => $this->bbMultiplier,
				],
			],
		];
	}

	// ------------------------------------------------------------------
	// Entry signal detection
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	protected function detectLongSignal(): bool {
		if (!$this->cooldownElapsed()) {
			return false;
		}

		if ($this->rsiNeutralFilter && !$this->rsiInNeutralZone()) {
			return false;
		}

		if (!$this->priceCrossesBelowLowerBand()) {
			return false;
		}

		if ($this->bbSlopeFilter && !$this->bandSlopeWithinLimit('lower')) {
			return false;
		}

		$this->markEntry();
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function detectShortSignal(): bool {
		if (!$this->cooldownElapsed()) {
			return false;
		}

		if ($this->rsiNeutralFilter && !$this->rsiInNeutralZone()) {
			return false;
		}

		if (!$this->priceCrossesAboveUpperBand()) {
			return false;
		}

		if ($this->bbSlopeFilter && !$this->bandSlopeWithinLimit('upper')) {
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
	// RSI neutral zone filter
	// ------------------------------------------------------------------

	/**
	 * Check if the current RSI value is inside the neutral zone.
	 * A neutral RSI (between low and high thresholds) indicates
	 * a sideways/range-bound market — ideal for mean-reversion entries.
	 */
	private function rsiInNeutralZone(): bool {
		$log = Logger::getLogger();
		$candles = $this->market->getCandles();

		if (count($candles) < $this->rsiPeriod + 1) {
			$log->debug("[RSI-NEUTRAL] Not enough candles for RSI calculation");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);
		$rsiValues = RSI::calculateFromPrices($closePrices, $this->rsiPeriod);

		if (empty($rsiValues)) {
			$log->debug("[RSI-NEUTRAL] RSI calculation returned empty");
			return false;
		}

		$latestRSI = end($rsiValues);
		$inZone = $latestRSI >= $this->rsiNeutralLow && $latestRSI <= $this->rsiNeutralHigh;

		$log->debug(sprintf(
			"[RSI-NEUTRAL] RSI=%.2f zone=[%.0f–%.0f] | %s",
			$latestRSI, $this->rsiNeutralLow, $this->rsiNeutralHigh,
			$inZone ? 'PASS — sideways market' : 'FAIL — trending/extreme',
		));

		return $inZone;
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

		// Apply penetration offset: require price to move bbOffset% beyond the band.
		$adjustedLower = $currLower * (1 - $this->bbOffset / 100);

		$cross = $prevClose > $prevLower && $currClose <= $adjustedLower;

		$log->debug(sprintf(
			"[BB↓] prevClose=%.8f prevLower=%.8f | currClose=%.8f adjLower=%.8f (offset=%.2f%%) | cross=%s",
			$prevClose, $prevLower, $currClose, $adjustedLower, $this->bbOffset,
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

		// Apply penetration offset: require price to move bbOffset% beyond the band.
		$adjustedUpper = $currUpper * (1 + $this->bbOffset / 100);

		$cross = $prevClose < $prevUpper && $currClose >= $adjustedUpper;

		$log->debug(sprintf(
			"[BB↑] prevClose=%.8f prevUpper=%.8f | currClose=%.8f adjUpper=%.8f (offset=%.2f%%) | cross=%s",
			$prevClose, $prevUpper, $currClose, $adjustedUpper, $this->bbOffset,
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	// ------------------------------------------------------------------
	// Bollinger Band slope filter
	// ------------------------------------------------------------------

	/**
	 * Check whether the slope of the specified band is within the allowed limit.
	 *
	 * Measures the percentage change of the band value over the last
	 * bbSlopePeriod candles. A steep slope means the band is trending
	 * strongly — price is "riding" the band rather than bouncing off it,
	 * so a mean-reversion entry is unlikely to succeed.
	 *
	 * @param string $band 'upper' or 'lower'.
	 */
	private function bandSlopeWithinLimit(string $band): bool {
		$log = Logger::getLogger();
		$bb = $this->calculateBB();
		if ($bb === null) {
			$log->debug("[BB-SLOPE] Not enough data");
			return true;
		}

		$bands = $bb['bands'];
		$count = count($bands);

		if ($count < $this->bbSlopePeriod + 1) {
			$log->debug("[BB-SLOPE] Not enough BB values for slope lookback");
			return true;
		}

		$current = $bands[$count - 1][$band];
		$previous = $bands[$count - 1 - $this->bbSlopePeriod][$band];

		if (abs($previous) < 1e-12) {
			return true;
		}

		$slopePercent = ($current - $previous) / $previous * 100;
		$pass = abs($slopePercent) <= $this->bbSlopeMax;

		$log->debug(sprintf(
			"[BB-SLOPE] %s band: %.8f → %.8f over %d candles = %+.4f%% (max ±%.4f%%) | %s",
			strtoupper($band), $previous, $current, $this->bbSlopePeriod,
			$slopePercent, $this->bbSlopeMax,
			$pass ? 'PASS — flat enough' : 'FAIL — band too steep',
		));

		return $pass;
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
	// Parameter definitions
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function getParameters(): array {
		return array_merge(parent::getParameters(), [
			new BBPeriod(),
			new BBMultiplier(),
			new BBOffset(),
			new BBSlopeFilter(),
			new BBSlopePeriod(),
			new BBSlopeMax(),
			new RSINeutralFilter(),
			new RSIPeriod(),
			new RSINeutralLow(),
			new RSINeutralHigh(),
			new CooldownCandles(),
		]);
	}
}
