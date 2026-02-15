<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Bollinger Bands indicator.
 *
 * Consists of three lines plotted relative to a simple moving average (SMA):
 *   - Middle band = SMA(period)
 *   - Upper band  = SMA + multiplier * StdDev
 *   - Lower band  = SMA - multiplier * StdDev
 *
 * The bands widen when volatility increases and narrow during consolidation.
 * Price touching or crossing the outer bands often signals a mean-reversion
 * opportunity, especially in ranging (non-trending) markets.
 */
class BollingerBands extends AbstractIndicator
{
	private const int DEFAULT_PERIOD = 20;
	private const float DEFAULT_MULTIPLIER = 2.0;

	public static function getName(): string {
		return 'BollingerBands';
	}

	/**
	 * Calculate Bollinger Bands for the given market.
	 *
	 * The result contains three parallel arrays packed into values/timestamps/signals:
	 *   - values    → middle band (SMA)
	 *   - timestamps → corresponding candle open-times
	 *   - signals   → array of ['upper' => float, 'lower' => float] per point
	 *
	 * @param IMarket $market Market with candle data.
	 * @return IndicatorResult Bollinger Bands result.
	 */
	public function calculate(IMarket $market): IndicatorResult {
		$period = $this->parameters['period'] ?? self::DEFAULT_PERIOD;
		$multiplier = (float)($this->parameters['multiplier'] ?? self::DEFAULT_MULTIPLIER);

		$candles = $market->getCandles();
		if (count($candles) < $period) {
			return new IndicatorResult([], [], []);
		}

		$closePrices = $this->getClosePrices($candles);
		$timestamps = $this->getTimestamps($candles);

		$result = self::calculateFromPrices($closePrices, $period, $multiplier);

		// Adjust timestamps: first $period-1 candles have no value.
		$bbTimestamps = array_slice($timestamps, $period - 1);

		return new IndicatorResult(
			$result['middle'],
			$bbTimestamps,
			$result['bands'],
		);
	}

	/**
	 * Calculate Bollinger Bands from an array of close prices.
	 *
	 * Can be called directly by strategies for multi-timeframe calculations
	 * without going through the indicator system.
	 *
	 * @param float[] $closePrices Close prices (chronological order).
	 * @param int $period SMA period (typically 20).
	 * @param float $multiplier Standard deviation multiplier (typically 2.0).
	 * @return array{middle: float[], bands: array<array{upper: float, lower: float}>}
	 */
	public static function calculateFromPrices(array $closePrices, int $period, float $multiplier = 2.0): array {
		$count = count($closePrices);
		if ($count < $period) {
			return ['middle' => [], 'bands' => []];
		}

		$middle = [];
		$bands = [];

		for ($i = $period - 1; $i < $count; $i++) {
			$window = array_slice($closePrices, $i - $period + 1, $period);
			$sma = array_sum($window) / $period;

			// Population standard deviation of the window.
			$variance = 0.0;
			foreach ($window as $price) {
				$variance += ($price - $sma) ** 2;
			}
			$stdDev = sqrt($variance / $period);

			$middle[] = $sma;
			$bands[] = [
				'upper' => $sma + $multiplier * $stdDev,
				'lower' => $sma - $multiplier * $stdDev,
			];
		}

		return ['middle' => $middle, 'bands' => $bands];
	}
}
