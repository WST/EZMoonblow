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
 * Incremental: maintains Wilder-smoothed TR/DM/DX state so each tick costs
 * O(1) instead of full O(n) recalculation.
 */
class ADX extends AbstractIndicator
{
	private const int DEFAULT_PERIOD = 14;

	// ── Base state (at second-to-last position) ──────────────────────
	private float $baseSmoothTR = 0.0;
	private float $baseSmoothPlusDM = 0.0;
	private float $baseSmoothMinusDM = 0.0;
	private float $baseADX = 0.0;

	// ── Current state (at last position, recomputed on each tick) ────
	private float $lastSmoothTR = 0.0;
	private float $lastSmoothPlusDM = 0.0;
	private float $lastSmoothMinusDM = 0.0;
	private float $lastADX = 0.0;

	/** @var float[] Incrementally maintained ADX values. */
	private array $adxValues = [];
	/** @var int[] Timestamps for each ADX value. */
	private array $adxTimestamps = [];

	private bool $initialized = false;

	public static function getName(): string {
		return 'ADX';
	}

	public function calculate(IMarket $market): IndicatorResult {
		$period = (int)($this->parameters['period'] ?? self::DEFAULT_PERIOD);

		$candles = $market->getCandles();
		$n = count($candles);

		if ($n < $period * 2) {
			return new IndicatorResult([], [], []);
		}

		$newCandles = $this->syncPrices($candles);

		if (!$this->initialized) {
			$this->fullCalculate($period);
			$this->initialized = true;
		} elseif ($newCandles > 0) {
			if ($newCandles > 1) {
				$this->fullCalculate($period);
			} else {
				// Advance base state from finalized previous values.
				$this->baseSmoothTR = $this->lastSmoothTR;
				$this->baseSmoothPlusDM = $this->lastSmoothPlusDM;
				$this->baseSmoothMinusDM = $this->lastSmoothMinusDM;
				$this->baseADX = $this->lastADX;

				$this->computeLastValue($n, $period);

				$this->adxValues[] = $this->lastADX;
				$this->adxTimestamps[] = $this->timestamps[$n - 1];
			}
		}

		// Partial candle update: recompute last ADX from base state.
		$this->computeLastValue($n, $period);
		$this->adxValues[count($this->adxValues) - 1] = $this->lastADX;

		return new IndicatorResult($this->adxValues, $this->adxTimestamps);
	}

	/**
	 * Recompute the last ADX value from base state + current candle data.
	 */
	private function computeLastValue(int $n, int $period): void {
		$i = $n - 1;
		$tr = $this->computeTR($i);
		$plusDM = $this->computePlusDM($i);
		$minusDM = $this->computeMinusDM($i);

		$this->lastSmoothTR = $this->baseSmoothTR - ($this->baseSmoothTR / $period) + $tr;
		$this->lastSmoothPlusDM = $this->baseSmoothPlusDM - ($this->baseSmoothPlusDM / $period) + $plusDM;
		$this->lastSmoothMinusDM = $this->baseSmoothMinusDM - ($this->baseSmoothMinusDM / $period) + $minusDM;

		$dx = $this->computeDX($this->lastSmoothTR, $this->lastSmoothPlusDM, $this->lastSmoothMinusDM);
		$this->lastADX = (($this->baseADX * ($period - 1)) + $dx) / $period;
	}

	private function computeTR(int $i): float {
		$highLow = $this->highPrices[$i] - $this->lowPrices[$i];
		$highPrevClose = abs($this->highPrices[$i] - $this->closePrices[$i - 1]);
		$lowPrevClose = abs($this->lowPrices[$i] - $this->closePrices[$i - 1]);
		return max($highLow, $highPrevClose, $lowPrevClose);
	}

	private function computePlusDM(int $i): float {
		$upMove = $this->highPrices[$i] - $this->highPrices[$i - 1];
		$downMove = $this->lowPrices[$i - 1] - $this->lowPrices[$i];
		return ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
	}

	private function computeMinusDM(int $i): float {
		$upMove = $this->highPrices[$i] - $this->highPrices[$i - 1];
		$downMove = $this->lowPrices[$i - 1] - $this->lowPrices[$i];
		return ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
	}

	private function computeDX(float $smoothTR, float $smoothPlusDM, float $smoothMinusDM): float {
		if ($smoothTR == 0.0) {
			return 0.0;
		}
		$plusDI = 100.0 * $smoothPlusDM / $smoothTR;
		$minusDI = 100.0 * $smoothMinusDM / $smoothTR;
		$diSum = $plusDI + $minusDI;
		if ($diSum == 0.0) {
			return 0.0;
		}
		return 100.0 * abs($plusDI - $minusDI) / $diSum;
	}

	/**
	 * Full calculation with base-state extraction.
	 */
	private function fullCalculate(int $period): void {
		$count = count($this->closePrices);

		// Step 1: Compute per-bar TR, +DM, -DM.
		$tr = [];
		$plusDM = [];
		$minusDM = [];

		for ($i = 1; $i < $count; $i++) {
			$tr[] = $this->computeTR($i);
			$plusDM[] = $this->computePlusDM($i);
			$minusDM[] = $this->computeMinusDM($i);
		}

		$barsCount = count($tr);
		if ($barsCount < $period) {
			$this->adxValues = [];
			$this->adxTimestamps = [];
			return;
		}

		// Step 2: First smoothed values = sum of first period bars.
		$smoothTR = array_sum(array_slice($tr, 0, $period));
		$smoothPlusDM = array_sum(array_slice($plusDM, 0, $period));
		$smoothMinusDM = array_sum(array_slice($minusDM, 0, $period));

		// Step 3: Compute DX values.
		$dx = [];
		$dxVal = $this->computeDX($smoothTR, $smoothPlusDM, $smoothMinusDM);
		$dx[] = $dxVal;

		for ($i = $period; $i < $barsCount; $i++) {
			$smoothTR = $smoothTR - ($smoothTR / $period) + $tr[$i];
			$smoothPlusDM = $smoothPlusDM - ($smoothPlusDM / $period) + $plusDM[$i];
			$smoothMinusDM = $smoothMinusDM - ($smoothMinusDM / $period) + $minusDM[$i];

			$dx[] = $this->computeDX($smoothTR, $smoothPlusDM, $smoothMinusDM);
		}

		if (count($dx) < $period) {
			$this->adxValues = [];
			$this->adxTimestamps = [];
			return;
		}

		// Step 4: First ADX = SMA of first period DX values.
		$firstADX = array_sum(array_slice($dx, 0, $period)) / $period;
		$this->adxValues = [$firstADX];
		$prevADX = $firstADX;

		for ($i = $period; $i < count($dx); $i++) {
			$currentADX = (($prevADX * ($period - 1)) + $dx[$i]) / $period;
			$this->adxValues[] = $currentADX;
			$prevADX = $currentADX;
		}

		$adxLen = count($this->adxValues);
		$this->adxTimestamps = array_slice($this->timestamps, count($this->timestamps) - $adxLen);

		// Extract base states (second-to-last positions).
		// The smooth values at second-to-last: replay Wilder smoothing to $barsCount - 2.
		$sTR = array_sum(array_slice($tr, 0, $period));
		$sPDM = array_sum(array_slice($plusDM, 0, $period));
		$sMDM = array_sum(array_slice($minusDM, 0, $period));
		for ($i = $period; $i < $barsCount - 1; $i++) {
			$sTR = $sTR - ($sTR / $period) + $tr[$i];
			$sPDM = $sPDM - ($sPDM / $period) + $plusDM[$i];
			$sMDM = $sMDM - ($sMDM / $period) + $minusDM[$i];
		}
		$this->baseSmoothTR = $sTR;
		$this->baseSmoothPlusDM = $sPDM;
		$this->baseSmoothMinusDM = $sMDM;

		$this->lastSmoothTR = $smoothTR;
		$this->lastSmoothPlusDM = $smoothPlusDM;
		$this->lastSmoothMinusDM = $smoothMinusDM;

		if ($adxLen >= 2) {
			$this->baseADX = $this->adxValues[$adxLen - 2];
		} else {
			$this->baseADX = $this->adxValues[0];
		}
		$this->lastADX = $this->adxValues[$adxLen - 1];
	}

	protected function resetState(): void {
		parent::resetState();
		$this->baseSmoothTR = 0.0;
		$this->baseSmoothPlusDM = 0.0;
		$this->baseSmoothMinusDM = 0.0;
		$this->baseADX = 0.0;
		$this->lastSmoothTR = 0.0;
		$this->lastSmoothPlusDM = 0.0;
		$this->lastSmoothMinusDM = 0.0;
		$this->lastADX = 0.0;
		$this->adxValues = [];
		$this->adxTimestamps = [];
		$this->initialized = false;
	}

	/**
	 * Calculate ADX from arrays of high, low, and close prices
	 * (stateless, used by strategies directly).
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
		if ($count < 2 * $period + 1) {
			return [];
		}

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

		$smoothTR = array_sum(array_slice($tr, 0, $period));
		$smoothPlusDM = array_sum(array_slice($plusDM, 0, $period));
		$smoothMinusDM = array_sum(array_slice($minusDM, 0, $period));

		$dx = [];

		$computeDX = function () use (&$smoothTR, &$smoothPlusDM, &$smoothMinusDM): float {
			if ($smoothTR == 0.0) return 0.0;
			$plusDI = 100.0 * $smoothPlusDM / $smoothTR;
			$minusDI = 100.0 * $smoothMinusDM / $smoothTR;
			$diSum = $plusDI + $minusDI;
			if ($diSum == 0.0) return 0.0;
			return 100.0 * abs($plusDI - $minusDI) / $diSum;
		};

		$dx[] = $computeDX();

		for ($i = $period; $i < $barsCount; $i++) {
			$smoothTR = $smoothTR - ($smoothTR / $period) + $tr[$i];
			$smoothPlusDM = $smoothPlusDM - ($smoothPlusDM / $period) + $plusDM[$i];
			$smoothMinusDM = $smoothMinusDM - ($smoothMinusDM / $period) + $minusDM[$i];
			$dx[] = $computeDX();
		}

		if (count($dx) < $period) {
			return [];
		}

		$adx = [];
		$firstADX = array_sum(array_slice($dx, 0, $period)) / $period;
		$adx[] = $firstADX;

		$prevADX = $firstADX;
		for ($i = $period; $i < count($dx); $i++) {
			$currentADX = (($prevADX * ($period - 1)) + $dx[$i]) / $period;
			$adx[] = $currentADX;
			$prevADX = $currentADX;
		}

		return $adx;
	}
}
