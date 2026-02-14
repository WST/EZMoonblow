<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Exponential Moving Average (EMA) indicator.
 * Applies more weight to recent prices, making it more responsive to new data than SMA.
 */
class EMA extends AbstractIndicator
{
	/**
	 * Default EMA period.
	 */
	private const int DEFAULT_PERIOD = 50;

	/**
	 * Get indicator name.
	 *
	 * @return string Indicator name.
	 */
	public static function getName(): string {
		return 'EMA';
	}

	/**
	 * Calculate EMA values for the given market.
	 *
	 * @param IMarket $market Market with candle data.
	 * @return IndicatorResult EMA calculation result.
	 */
	public function calculate(IMarket $market): IndicatorResult {
		$period = $this->parameters['period'] ?? self::DEFAULT_PERIOD;

		$candles = $market->getCandles();
		if (count($candles) < $period) {
			return new IndicatorResult([], [], []);
		}

		$closePrices = $this->getClosePrices($candles);
		$timestamps = $this->getTimestamps($candles);

		$emaValues = self::calculateFromPrices($closePrices, $period);

		// Adjust timestamps to match EMA values (skip first period - 1 candles).
		$emaTimestamps = array_slice($timestamps, $period - 1);

		return new IndicatorResult($emaValues, $emaTimestamps);
	}

	/**
	 * Calculate EMA from an array of close prices.
	 *
	 * Can be used directly by strategies for multi-timeframe calculations
	 * without going through the indicator system.
	 *
	 * @param array $closePrices Array of close prices (chronological order).
	 * @param int $period EMA period.
	 * @return array Array of EMA values. Length = count($closePrices) - $period + 1.
	 */
	public static function calculateFromPrices(array $closePrices, int $period): array {
		$count = count($closePrices);
		if ($count < $period) {
			return [];
		}

		$k = 2.0 / ($period + 1);
		$ema = [];

		// First EMA value = SMA of the first $period prices.
		$sma = array_sum(array_slice($closePrices, 0, $period)) / $period;
		$ema[] = $sma;

		// Subsequent values use the EMA formula: EMA_t = Close_t * k + EMA_(t-1) * (1 - k).
		$previousEma = $sma;
		for ($i = $period; $i < $count; $i++) {
			$currentEma = $closePrices[$i] * $k + $previousEma * (1 - $k);
			$ema[] = $currentEma;
			$previousEma = $currentEma;
		}

		return $ema;
	}
}
