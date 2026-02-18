<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Average Directional Index (ADX) indicator.
 *
 * Measures the strength of a trend regardless of its direction.
 * ADX > 20-25 typically indicates a trending market; below that the market
 * is considered range-bound.
 *
 * Calculation steps (Wilder's smoothing):
 *   1. True Range (TR), +DM, -DM per bar
 *   2. Smooth TR, +DM, -DM over `period` bars (Wilder's method)
 *   3. +DI = 100 * Smoothed(+DM) / Smoothed(TR)
 *   4. -DI = 100 * Smoothed(-DM) / Smoothed(TR)
 *   5. DX  = 100 * |+DI - -DI| / (+DI + -DI)
 *   6. ADX = Wilder-smoothed DX over `period` bars
 */
class ADX extends AbstractIndicator
{
	private const int DEFAULT_PERIOD = 14;

	public static function getName(): string {
		return 'ADX';
	}

	/**
	 * Calculate ADX for the given market.
	 *
	 * @param IMarket $market Market with candle data.
	 * @return IndicatorResult ADX values and timestamps.
	 */
	public function calculate(IMarket $market): IndicatorResult {
		$period = (int)($this->parameters['period'] ?? self::DEFAULT_PERIOD);

		$candles = $market->getCandles();
		// Need at least 2*period candles for meaningful ADX output.
		if (count($candles) < $period * 2) {
			return new IndicatorResult([], [], []);
		}

		$highPrices = array_map(fn($c) => $c->getHighPrice(), $candles);
		$lowPrices = array_map(fn($c) => $c->getLowPrice(), $candles);
		$closePrices = $this->getClosePrices($candles);
		$timestamps = $this->getTimestamps($candles);

		$adxValues = self::calculateFromPrices($highPrices, $lowPrices, $closePrices, $period);
		if (empty($adxValues)) {
			return new IndicatorResult([], [], []);
		}

		$adxTimestamps = array_slice($timestamps, count($timestamps) - count($adxValues));
		return new IndicatorResult($adxValues, $adxTimestamps);
	}

	/**
	 * Calculate ADX from arrays of high, low, and close prices.
	 *
	 * Can be called directly by strategies without going through the
	 * indicator system.
	 *
	 * @param float[] $highPrices  High prices (chronological order).
	 * @param float[] $lowPrices   Low prices (chronological order).
	 * @param float[] $closePrices Close prices (chronological order).
	 * @param int $period ADX period (typically 14).
	 * @return float[] Array of ADX values.
	 */
	public static function calculateFromPrices(
		array $highPrices,
		array $lowPrices,
		array $closePrices,
		int $period = 14,
	): array {
		$count = count($closePrices);
		// Minimum bars: 1 (for prev close) + period (smoothing) + period (ADX smoothing).
		if ($count < 2 * $period + 1) {
			return [];
		}

		// Step 1: Compute per-bar TR, +DM, -DM (starting from index 1).
		$tr = [];
		$plusDM = [];
		$minusDM = [];

		for ($i = 1; $i < $count; $i++) {
			$highLow = $highPrices[$i] - $lowPrices[$i];
			$highPrevClose = abs($highPrices[$i] - $closePrices[$i - 1]);
			$lowPrevClose = abs($lowPrices[$i] - $closePrices[$i - 1]);
			$tr[] = max($highLow, $highPrevClose, $lowPrevClose);

			$upMove = $highPrices[$i] - $highPrices[$i - 1];
			$downMove = $lowPrices[$i - 1] - $lowPrices[$i];

			$plusDM[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
			$minusDM[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
		}

		$barsCount = count($tr);
		if ($barsCount < $period) {
			return [];
		}

		// Step 2: First smoothed values = sum of the first `period` bars.
		$smoothTR = array_sum(array_slice($tr, 0, $period));
		$smoothPlusDM = array_sum(array_slice($plusDM, 0, $period));
		$smoothMinusDM = array_sum(array_slice($minusDM, 0, $period));

		// Step 3: Compute +DI, -DI, DX for each bar from `period` onward
		// using Wilder's smoothing: Smoothed_t = Smoothed_(t-1) - Smoothed_(t-1)/period + value_t.
		$dx = [];

		$computeDX = function () use ($smoothTR, $smoothPlusDM, $smoothMinusDM): ?float {
			if ($smoothTR == 0.0) {
				return null;
			}
			$plusDI = 100.0 * $smoothPlusDM / $smoothTR;
			$minusDI = 100.0 * $smoothMinusDM / $smoothTR;
			$diSum = $plusDI + $minusDI;
			if ($diSum == 0.0) {
				return 0.0;
			}
			return 100.0 * abs($plusDI - $minusDI) / $diSum;
		};

		// First DX value from the initial smoothed sums.
		$dxVal = $computeDX();
		if ($dxVal !== null) {
			$dx[] = $dxVal;
		}

		for ($i = $period; $i < $barsCount; $i++) {
			$smoothTR = $smoothTR - ($smoothTR / $period) + $tr[$i];
			$smoothPlusDM = $smoothPlusDM - ($smoothPlusDM / $period) + $plusDM[$i];
			$smoothMinusDM = $smoothMinusDM - ($smoothMinusDM / $period) + $minusDM[$i];

			$dxVal = $computeDX();
			if ($dxVal !== null) {
				$dx[] = $dxVal;
			}
		}

		if (count($dx) < $period) {
			return [];
		}

		// Step 4: First ADX = SMA of first `period` DX values.
		$adx = [];
		$firstADX = array_sum(array_slice($dx, 0, $period)) / $period;
		$adx[] = $firstADX;

		// Subsequent ADX values: Wilder's smoothing of DX.
		$prevADX = $firstADX;
		for ($i = $period; $i < count($dx); $i++) {
			$currentADX = (($prevADX * ($period - 1)) + $dx[$i]) / $period;
			$adx[] = $currentADX;
			$prevADX = $currentADX;
		}

		return $adx;
	}
}
