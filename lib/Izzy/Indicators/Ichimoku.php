<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Ichimoku Cloud (Ichimoku Kinko Hyo) indicator.
 *
 * A comprehensive indicator that defines support/resistance, trend direction,
 * momentum, and trading signals all at once. Consists of five components:
 *
 *   - Tenkan-sen (Conversion Line) = midpoint of highest high and lowest low
 *     over tenkanPeriod (default 9)
 *   - Kijun-sen (Base Line) = midpoint over kijunPeriod (default 26)
 *   - Senkou Span A (Leading Span A) = (Tenkan + Kijun) / 2, plotted
 *     displacement periods ahead
 *   - Senkou Span B (Leading Span B) = midpoint over senkouBPeriod (default 52),
 *     plotted displacement periods ahead
 *   - Chikou Span (Lagging Span) = close price plotted displacement periods behind
 *
 * The "cloud" (Kumo) is the shaded area between Senkou Span A and Senkou Span B.
 */
class Ichimoku extends AbstractIndicator
{
	private const int DEFAULT_TENKAN_PERIOD = 9;
	private const int DEFAULT_KIJUN_PERIOD = 26;
	private const int DEFAULT_SENKOU_B_PERIOD = 52;
	private const int DEFAULT_DISPLACEMENT = 26;

	public static function getName(): string {
		return 'Ichimoku';
	}

	/**
	 * Calculate Ichimoku for the given market via the indicator system.
	 *
	 * Returns Tenkan-sen values, corresponding timestamps, and all other
	 * components packed into the signals array.
	 *
	 * @param IMarket $market Market with candle data.
	 * @return IndicatorResult Ichimoku calculation result.
	 */
	public function calculate(IMarket $market): IndicatorResult {
		$tenkanPeriod = (int)($this->parameters['tenkanPeriod'] ?? self::DEFAULT_TENKAN_PERIOD);
		$kijunPeriod = (int)($this->parameters['kijunPeriod'] ?? self::DEFAULT_KIJUN_PERIOD);
		$senkouBPeriod = (int)($this->parameters['senkouBPeriod'] ?? self::DEFAULT_SENKOU_B_PERIOD);
		$displacement = (int)($this->parameters['displacement'] ?? self::DEFAULT_DISPLACEMENT);

		$candles = $market->getCandles();
		$minRequired = $senkouBPeriod + $displacement;
		if (count($candles) < $minRequired) {
			return new IndicatorResult([], [], []);
		}

		$highPrices = array_map(fn($c) => $c->getHighPrice(), $candles);
		$lowPrices = array_map(fn($c) => $c->getLowPrice(), $candles);
		$closePrices = $this->getClosePrices($candles);
		$timestamps = $this->getTimestamps($candles);

		$result = self::calculateFromPrices(
			$highPrices, $lowPrices, $closePrices,
			$tenkanPeriod, $kijunPeriod, $senkouBPeriod, $displacement,
		);

		if (empty($result['tenkan'])) {
			return new IndicatorResult([], [], []);
		}

		$tenkanLen = count($result['tenkan']);
		$trimmedTimestamps = array_slice($timestamps, count($timestamps) - $tenkanLen);

		return new IndicatorResult(
			$result['tenkan'],
			$trimmedTimestamps,
			[
				'kijun' => $result['kijun'],
				'senkouA' => $result['senkouA'],
				'senkouB' => $result['senkouB'],
				'chikou' => $result['chikou'],
			],
		);
	}

	/**
	 * Calculate Ichimoku components from arrays of high, low, and close prices.
	 *
	 * Can be called directly by strategies without going through the
	 * indicator system, allowing strategy-specific parameter combinations.
	 *
	 * All returned arrays are aligned to the input arrays: index i corresponds
	 * to bar i of the original data. Entries that cannot be computed (not enough
	 * lookback) are set to NAN.
	 *
	 * @param float[] $highPrices  High prices (chronological order).
	 * @param float[] $lowPrices   Low prices (chronological order).
	 * @param float[] $closePrices Close prices (chronological order).
	 * @param int $tenkanPeriod  Tenkan-sen period (typically 9).
	 * @param int $kijunPeriod   Kijun-sen period (typically 26).
	 * @param int $senkouBPeriod Senkou Span B period (typically 52).
	 * @param int $displacement  Cloud displacement / Chikou shift (typically 26).
	 * @return array{tenkan: float[], kijun: float[], senkouA: float[], senkouB: float[], chikou: float[]}
	 */
	public static function calculateFromPrices(
		array $highPrices,
		array $lowPrices,
		array $closePrices,
		int $tenkanPeriod = 9,
		int $kijunPeriod = 26,
		int $senkouBPeriod = 52,
		int $displacement = 26,
	): array {
		$count = count($closePrices);
		$empty = ['tenkan' => [], 'kijun' => [], 'senkouA' => [], 'senkouB' => [], 'chikou' => []];

		if ($count < max($tenkanPeriod, $kijunPeriod, $senkouBPeriod)) {
			return $empty;
		}

		$tenkan = array_fill(0, $count, NAN);
		$kijun = array_fill(0, $count, NAN);
		$senkouARaw = array_fill(0, $count, NAN);
		$senkouBRaw = array_fill(0, $count, NAN);

		// Compute Tenkan, Kijun, raw Senkou A and raw Senkou B for each bar.
		for ($i = 0; $i < $count; $i++) {
			if ($i >= $tenkanPeriod - 1) {
				$tenkan[$i] = self::midpoint($highPrices, $lowPrices, $tenkanPeriod, $i);
			}
			if ($i >= $kijunPeriod - 1) {
				$kijun[$i] = self::midpoint($highPrices, $lowPrices, $kijunPeriod, $i);
			}
			if ($i >= $kijunPeriod - 1 && !is_nan($tenkan[$i]) && !is_nan($kijun[$i])) {
				$senkouARaw[$i] = ($tenkan[$i] + $kijun[$i]) / 2.0;
			}
			if ($i >= $senkouBPeriod - 1) {
				$senkouBRaw[$i] = self::midpoint($highPrices, $lowPrices, $senkouBPeriod, $i);
			}
		}

		// Senkou Span A/B: shift forward by displacement periods.
		// senkouA[j] represents the cloud value at bar j, which was calculated
		// at bar j - displacement.
		$senkouA = array_fill(0, $count, NAN);
		$senkouB = array_fill(0, $count, NAN);

		for ($i = 0; $i < $count; $i++) {
			$target = $i + $displacement;
			if ($target < $count) {
				if (!is_nan($senkouARaw[$i])) {
					$senkouA[$target] = $senkouARaw[$i];
				}
				if (!is_nan($senkouBRaw[$i])) {
					$senkouB[$target] = $senkouBRaw[$i];
				}
			}
		}

		// Chikou Span: close[i] plotted at position i - displacement.
		// For strategy signal detection we store it aligned to source bar:
		// chikou[i] = close[i], and the strategy compares close[current]
		// against close[current - displacement].
		$chikou = $closePrices;

		return [
			'tenkan' => $tenkan,
			'kijun' => $kijun,
			'senkouA' => $senkouA,
			'senkouB' => $senkouB,
			'chikou' => $chikou,
		];
	}

	/**
	 * Compute the midpoint: (highest high + lowest low) / 2 over the last
	 * $period bars ending at $index (inclusive).
	 *
	 * @param float[] $highPrices High prices array.
	 * @param float[] $lowPrices  Low prices array.
	 * @param int $period Lookback period.
	 * @param int $index  Current bar index (inclusive end).
	 * @return float Midpoint value.
	 */
	private static function midpoint(array $highPrices, array $lowPrices, int $period, int $index): float {
		$start = $index - $period + 1;
		$highestHigh = PHP_FLOAT_MIN;
		$lowestLow = PHP_FLOAT_MAX;

		for ($i = $start; $i <= $index; $i++) {
			if ($highPrices[$i] > $highestHigh) {
				$highestHigh = $highPrices[$i];
			}
			if ($lowPrices[$i] < $lowestLow) {
				$lowestLow = $lowPrices[$i];
			}
		}

		return ($highestHigh + $lowestLow) / 2.0;
	}
}
