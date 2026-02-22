<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Exponential Moving Average (EMA) indicator.
 * Applies more weight to recent prices, making it more responsive to new data than SMA.
 *
 * Incremental: after the initial full calculation, each subsequent tick
 * recomputes only the last EMA value in O(1).
 */
class EMA extends AbstractIndicator
{
	private const int DEFAULT_PERIOD = 50;

	/** EMA value at the second-to-last position (base for recomputing the last value). */
	private float $baseEMA = 0.0;

	/** @var float[] Incrementally maintained EMA values. */
	private array $emaValues = [];

	/** @var int[] Corresponding timestamps for each EMA value. */
	private array $emaTimestamps = [];

	private bool $initialized = false;

	public static function getName(): string {
		return 'EMA';
	}

	public function calculate(IMarket $market): IndicatorResult {
		$period = $this->parameters['period'] ?? self::DEFAULT_PERIOD;
		$candles = $market->getCandles();
		$n = count($candles);

		if ($n < $period) {
			return new IndicatorResult([], [], []);
		}

		$newCandles = $this->syncPrices($candles);
		$k = 2.0 / ($period + 1);

		if (!$this->initialized) {
			$this->emaValues = self::calculateFromPrices($this->closePrices, $period);
			$this->emaTimestamps = array_slice($this->timestamps, $period - 1);
			$cnt = count($this->emaValues);
			$this->baseEMA = $cnt >= 2 ? $this->emaValues[$cnt - 2] : $this->emaValues[0];
			$this->initialized = true;
		} elseif ($newCandles > 0) {
			// The previous last value was computed with the finalized close (last tick = actual close).
			$this->baseEMA = $this->emaValues[count($this->emaValues) - 1];

			if ($newCandles > 1) {
				// Rare edge case: full recalculation.
				$this->emaValues = self::calculateFromPrices($this->closePrices, $period);
				$this->emaTimestamps = array_slice($this->timestamps, $period - 1);
				$cnt = count($this->emaValues);
				$this->baseEMA = $cnt >= 2 ? $this->emaValues[$cnt - 2] : $this->emaValues[0];
			} else {
				$newEMA = $this->closePrices[$n - 1] * $k + $this->baseEMA * (1 - $k);
				$this->emaValues[] = $newEMA;
				$this->emaTimestamps[] = $this->timestamps[$n - 1];
			}
		}

		// Partial candle update: recompute last EMA from baseEMA + current close.
		$this->emaValues[count($this->emaValues) - 1] =
			$this->closePrices[$n - 1] * $k + $this->baseEMA * (1 - $k);

		return new IndicatorResult($this->emaValues, $this->emaTimestamps);
	}

	protected function resetState(): void {
		parent::resetState();
		$this->baseEMA = 0.0;
		$this->emaValues = [];
		$this->emaTimestamps = [];
		$this->initialized = false;
	}

	/**
	 * Calculate EMA from an array of close prices (stateless, used by strategies directly).
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

		$sma = array_sum(array_slice($closePrices, 0, $period)) / $period;
		$ema[] = $sma;

		$previousEma = $sma;
		for ($i = $period; $i < $count; $i++) {
			$currentEma = $closePrices[$i] * $k + $previousEma * (1 - $k);
			$ema[] = $currentEma;
			$previousEma = $currentEma;
		}

		return $ema;
	}
}
