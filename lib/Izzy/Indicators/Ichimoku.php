<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Ichimoku Cloud (Ichimoku Kinko Hyo) indicator.
 *
 * A comprehensive indicator that defines support/resistance, trend direction,
 * momentum, and trading signals all at once.
 *
 * Incremental: on each tick only the current candle's components are
 * recomputed via O(period) midpoint lookups, instead of recalculating
 * all candles O(n × period).
 */
class Ichimoku extends AbstractIndicator
{
	private const int DEFAULT_TENKAN_PERIOD = 9;
	private const int DEFAULT_KIJUN_PERIOD = 26;
	private const int DEFAULT_SENKOU_B_PERIOD = 52;
	private const int DEFAULT_DISPLACEMENT = 26;

	/** @var float[] Tenkan-sen values (NAN where not enough lookback). */
	private array $tenkan = [];
	/** @var float[] Kijun-sen values. */
	private array $kijun = [];
	/** @var float[] Raw Senkou Span A (before displacement shift). */
	private array $senkouARaw = [];
	/** @var float[] Raw Senkou Span B (before displacement shift). */
	private array $senkouBRaw = [];
	/** @var float[] Shifted Senkou Span A. */
	private array $senkouA = [];
	/** @var float[] Shifted Senkou Span B. */
	private array $senkouB = [];
	/** @var float[] Chikou Span (= close prices). */
	private array $chikou = [];

	private bool $initialized = false;

	public static function getName(): string {
		return 'Ichimoku';
	}

	public function calculate(IMarket $market): IndicatorResult {
		$tenkanPeriod = (int)($this->parameters['tenkanPeriod'] ?? self::DEFAULT_TENKAN_PERIOD);
		$kijunPeriod = (int)($this->parameters['kijunPeriod'] ?? self::DEFAULT_KIJUN_PERIOD);
		$senkouBPeriod = (int)($this->parameters['senkouBPeriod'] ?? self::DEFAULT_SENKOU_B_PERIOD);
		$displacement = (int)($this->parameters['displacement'] ?? self::DEFAULT_DISPLACEMENT);

		$candles = $market->getCandles();
		$n = count($candles);
		$minRequired = $senkouBPeriod + $displacement;

		if ($n < $minRequired) {
			return new IndicatorResult([], [], []);
		}

		$newCandles = $this->syncPrices($candles);

		if (!$this->initialized) {
			$this->fullCalculate($tenkanPeriod, $kijunPeriod, $senkouBPeriod, $displacement);
			$this->initialized = true;
		} elseif ($newCandles > 0) {
			if ($newCandles > 1) {
				$this->fullCalculate($tenkanPeriod, $kijunPeriod, $senkouBPeriod, $displacement);
			} else {
				$this->extendByOne($n, $tenkanPeriod, $kijunPeriod, $senkouBPeriod, $displacement);
			}
		}

		// Partial candle update: recompute only the last position.
		$this->updateLastPosition($n, $tenkanPeriod, $kijunPeriod, $senkouBPeriod, $displacement);

		return new IndicatorResult(
			$this->tenkan,
			$this->timestamps,
			[
				'kijun' => $this->kijun,
				'senkouA' => $this->senkouA,
				'senkouB' => $this->senkouB,
				'chikou' => $this->chikou,
			],
		);
	}

	/**
	 * Extend all arrays by one position for a new candle.
	 */
	private function extendByOne(
		int $n,
		int $tenkanPeriod,
		int $kijunPeriod,
		int $senkouBPeriod,
		int $displacement,
	): void {
		$i = $n - 1;

		$this->tenkan[] = NAN;
		$this->kijun[] = NAN;
		$this->senkouARaw[] = NAN;
		$this->senkouBRaw[] = NAN;
		$this->senkouA[] = NAN;
		$this->senkouB[] = NAN;
		$this->chikou[] = NAN;

		$this->computeAt($i, $tenkanPeriod, $kijunPeriod, $senkouBPeriod, $displacement);
	}

	/**
	 * Recompute all Ichimoku components at a single position.
	 */
	private function computeAt(
		int $i,
		int $tenkanPeriod,
		int $kijunPeriod,
		int $senkouBPeriod,
		int $displacement,
	): void {
		if ($i >= $tenkanPeriod - 1) {
			$this->tenkan[$i] = $this->midpoint($tenkanPeriod, $i);
		}
		if ($i >= $kijunPeriod - 1) {
			$this->kijun[$i] = $this->midpoint($kijunPeriod, $i);
		}
		if (!is_nan($this->tenkan[$i]) && !is_nan($this->kijun[$i])) {
			$this->senkouARaw[$i] = ($this->tenkan[$i] + $this->kijun[$i]) / 2.0;
		}
		if ($i >= $senkouBPeriod - 1) {
			$this->senkouBRaw[$i] = $this->midpoint($senkouBPeriod, $i);
		}

		// Apply displacement shift: senkouA/B at position $i come from raw at $i - displacement.
		$srcIdx = $i - $displacement;
		if ($srcIdx >= 0) {
			if (!is_nan($this->senkouARaw[$srcIdx])) {
				$this->senkouA[$i] = $this->senkouARaw[$srcIdx];
			}
			if (!is_nan($this->senkouBRaw[$srcIdx])) {
				$this->senkouB[$i] = $this->senkouBRaw[$srcIdx];
			}
		}

		$this->chikou[$i] = $this->closePrices[$i];
	}

	/**
	 * Update only the last position (partial candle update).
	 */
	private function updateLastPosition(
		int $n,
		int $tenkanPeriod,
		int $kijunPeriod,
		int $senkouBPeriod,
		int $displacement,
	): void {
		$i = $n - 1;
		$this->tenkan[$i] = NAN;
		$this->kijun[$i] = NAN;
		$this->senkouARaw[$i] = NAN;
		$this->computeAt($i, $tenkanPeriod, $kijunPeriod, $senkouBPeriod, $displacement);
	}

	/**
	 * Full calculation of all positions.
	 */
	private function fullCalculate(
		int $tenkanPeriod,
		int $kijunPeriod,
		int $senkouBPeriod,
		int $displacement,
	): void {
		$count = count($this->closePrices);

		$this->tenkan = array_fill(0, $count, NAN);
		$this->kijun = array_fill(0, $count, NAN);
		$this->senkouARaw = array_fill(0, $count, NAN);
		$this->senkouBRaw = array_fill(0, $count, NAN);
		$this->senkouA = array_fill(0, $count, NAN);
		$this->senkouB = array_fill(0, $count, NAN);
		$this->chikou = $this->closePrices;

		for ($i = 0; $i < $count; $i++) {
			if ($i >= $tenkanPeriod - 1) {
				$this->tenkan[$i] = $this->midpoint($tenkanPeriod, $i);
			}
			if ($i >= $kijunPeriod - 1) {
				$this->kijun[$i] = $this->midpoint($kijunPeriod, $i);
			}
			if (!is_nan($this->tenkan[$i]) && !is_nan($this->kijun[$i])) {
				$this->senkouARaw[$i] = ($this->tenkan[$i] + $this->kijun[$i]) / 2.0;
			}
			if ($i >= $senkouBPeriod - 1) {
				$this->senkouBRaw[$i] = $this->midpoint($senkouBPeriod, $i);
			}
		}

		// Apply displacement shift.
		for ($i = 0; $i < $count; $i++) {
			$target = $i + $displacement;
			if ($target < $count) {
				if (!is_nan($this->senkouARaw[$i])) {
					$this->senkouA[$target] = $this->senkouARaw[$i];
				}
				if (!is_nan($this->senkouBRaw[$i])) {
					$this->senkouB[$target] = $this->senkouBRaw[$i];
				}
			}
		}
	}

	/**
	 * Compute the midpoint: (highest high + lowest low) / 2 over the last
	 * $period bars ending at $index (inclusive).
	 */
	private function midpoint(int $period, int $index): float {
		$start = $index - $period + 1;
		$highestHigh = PHP_FLOAT_MIN;
		$lowestLow = PHP_FLOAT_MAX;

		for ($i = $start; $i <= $index; $i++) {
			if ($this->highPrices[$i] > $highestHigh) {
				$highestHigh = $this->highPrices[$i];
			}
			if ($this->lowPrices[$i] < $lowestLow) {
				$lowestLow = $this->lowPrices[$i];
			}
		}

		return ($highestHigh + $lowestLow) / 2.0;
	}

	protected function resetState(): void {
		parent::resetState();
		$this->tenkan = [];
		$this->kijun = [];
		$this->senkouARaw = [];
		$this->senkouBRaw = [];
		$this->senkouA = [];
		$this->senkouB = [];
		$this->chikou = [];
		$this->initialized = false;
	}

	/**
	 * Calculate Ichimoku components from arrays of high, low, and close prices
	 * (stateless, used by strategies directly).
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

		for ($i = 0; $i < $count; $i++) {
			if ($i >= $tenkanPeriod - 1) {
				$tenkan[$i] = self::staticMidpoint($highPrices, $lowPrices, $tenkanPeriod, $i);
			}
			if ($i >= $kijunPeriod - 1) {
				$kijun[$i] = self::staticMidpoint($highPrices, $lowPrices, $kijunPeriod, $i);
			}
			if ($i >= $kijunPeriod - 1 && !is_nan($tenkan[$i]) && !is_nan($kijun[$i])) {
				$senkouARaw[$i] = ($tenkan[$i] + $kijun[$i]) / 2.0;
			}
			if ($i >= $senkouBPeriod - 1) {
				$senkouBRaw[$i] = self::staticMidpoint($highPrices, $lowPrices, $senkouBPeriod, $i);
			}
		}

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

		return [
			'tenkan' => $tenkan,
			'kijun' => $kijun,
			'senkouA' => $senkouA,
			'senkouB' => $senkouB,
			'chikou' => $closePrices,
		];
	}

	/**
	 * Static midpoint for the stateless calculateFromPrices method.
	 */
	private static function staticMidpoint(array $highPrices, array $lowPrices, int $period, int $index): float {
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
