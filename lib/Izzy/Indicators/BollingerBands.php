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
 * Incremental: maintains a rolling sum and sum-of-squares for the sliding
 * window, so each tick costs O(1) instead of O(n × period).
 */
class BollingerBands extends AbstractIndicator
{
	private const int DEFAULT_PERIOD = 20;
	private const float DEFAULT_MULTIPLIER = 2.0;

	/** Rolling sum of prices in the current window. */
	private float $rollingSum = 0.0;
	/** Rolling sum of squared prices in the current window. */
	private float $rollingSumSq = 0.0;
	/** The last close price that was included in the rolling window tail. */
	private float $lastWindowTail = 0.0;

	/** @var float[] Middle band values. */
	private array $middleValues = [];
	/** @var array<array{upper: float, lower: float}> Band values. */
	private array $bandValues = [];
	/** @var int[] Timestamps for each band value. */
	private array $bbTimestamps = [];

	private bool $initialized = false;

	public static function getName(): string {
		return 'BollingerBands';
	}

	public function calculate(IMarket $market): IndicatorResult {
		$period = $this->parameters['period'] ?? self::DEFAULT_PERIOD;
		$multiplier = (float)($this->parameters['multiplier'] ?? self::DEFAULT_MULTIPLIER);

		$candles = $market->getCandles();
		$n = count($candles);

		if ($n < $period) {
			return new IndicatorResult([], [], []);
		}

		$newCandles = $this->syncPrices($candles);

		if (!$this->initialized) {
			$result = self::calculateFromPrices($this->closePrices, $period, $multiplier);
			$this->middleValues = $result['middle'];
			$this->bandValues = $result['bands'];
			$this->bbTimestamps = array_slice($this->timestamps, $period - 1);

			$this->initRollingState($n, $period);
			$this->initialized = true;
		} elseif ($newCandles > 0) {
			if ($newCandles > 1) {
				$this->initialized = false;
				return $this->calculate($market);
			}

			// Slide the window: remove the price that's leaving, add the new one.
			$removedIdx = $n - $period - 1;
			$removedPrice = $this->closePrices[$removedIdx];
			$addedPrice = $this->closePrices[$n - 1];

			$this->rollingSum = $this->rollingSum - $removedPrice + $addedPrice;
			$this->rollingSumSq = $this->rollingSumSq
				- ($removedPrice * $removedPrice)
				+ ($addedPrice * $addedPrice);
			$this->lastWindowTail = $addedPrice;

			$this->appendBand($period, $multiplier, $n);
		}

		// Partial candle update: replace the tail of the rolling window.
		$newTail = $this->closePrices[$n - 1];
		if ($newTail !== $this->lastWindowTail) {
			$this->rollingSum = $this->rollingSum - $this->lastWindowTail + $newTail;
			$this->rollingSumSq = $this->rollingSumSq
				- ($this->lastWindowTail * $this->lastWindowTail)
				+ ($newTail * $newTail);
			$this->lastWindowTail = $newTail;
		}

		$this->updateLastBand($period, $multiplier);

		return new IndicatorResult($this->middleValues, $this->bbTimestamps, $this->bandValues);
	}

	private function initRollingState(int $n, int $period): void {
		$this->rollingSum = 0.0;
		$this->rollingSumSq = 0.0;
		$windowStart = $n - $period;
		for ($i = $windowStart; $i < $n; $i++) {
			$p = $this->closePrices[$i];
			$this->rollingSum += $p;
			$this->rollingSumSq += $p * $p;
		}
		$this->lastWindowTail = $this->closePrices[$n - 1];
	}

	private function appendBand(int $period, float $multiplier, int $n): void {
		$sma = $this->rollingSum / $period;
		$variance = ($this->rollingSumSq / $period) - ($sma * $sma);
		$stdDev = sqrt(max(0.0, $variance));

		$this->middleValues[] = $sma;
		$this->bandValues[] = [
			'upper' => $sma + $multiplier * $stdDev,
			'lower' => $sma - $multiplier * $stdDev,
		];
		$this->bbTimestamps[] = $this->timestamps[$n - 1];
	}

	private function updateLastBand(int $period, float $multiplier): void {
		$sma = $this->rollingSum / $period;
		$variance = ($this->rollingSumSq / $period) - ($sma * $sma);
		$stdDev = sqrt(max(0.0, $variance));

		$lastIdx = count($this->middleValues) - 1;
		$this->middleValues[$lastIdx] = $sma;
		$this->bandValues[$lastIdx] = [
			'upper' => $sma + $multiplier * $stdDev,
			'lower' => $sma - $multiplier * $stdDev,
		];
	}

	protected function resetState(): void {
		parent::resetState();
		$this->rollingSum = 0.0;
		$this->rollingSumSq = 0.0;
		$this->lastWindowTail = 0.0;
		$this->middleValues = [];
		$this->bandValues = [];
		$this->bbTimestamps = [];
		$this->initialized = false;
	}

	/**
	 * Calculate Bollinger Bands from an array of close prices (stateless, used by strategies directly).
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

		// Use rolling window for the static method too.
		$sum = 0.0;
		$sumSq = 0.0;
		for ($i = 0; $i < $period; $i++) {
			$sum += $closePrices[$i];
			$sumSq += $closePrices[$i] * $closePrices[$i];
		}

		$sma = $sum / $period;
		$variance = ($sumSq / $period) - ($sma * $sma);
		$stdDev = sqrt(max(0.0, $variance));
		$middle[] = $sma;
		$bands[] = [
			'upper' => $sma + $multiplier * $stdDev,
			'lower' => $sma - $multiplier * $stdDev,
		];

		for ($i = $period; $i < $count; $i++) {
			$removed = $closePrices[$i - $period];
			$added = $closePrices[$i];
			$sum = $sum - $removed + $added;
			$sumSq = $sumSq - ($removed * $removed) + ($added * $added);

			$sma = $sum / $period;
			$variance = ($sumSq / $period) - ($sma * $sma);
			$stdDev = sqrt(max(0.0, $variance));

			$middle[] = $sma;
			$bands[] = [
				'upper' => $sma + $multiplier * $stdDev,
				'lower' => $sma - $multiplier * $stdDev,
			];
		}

		return ['middle' => $middle, 'bands' => $bands];
	}
}
