<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Relative Strength Index (RSI) indicator.
 * Measures the speed and magnitude of price changes to identify overbought or oversold conditions.
 *
 * Incremental: after the initial full calculation, each subsequent tick
 * recomputes only the last RSI value in O(1).
 */
class RSI extends AbstractIndicator
{
	private const int DEFAULT_PERIOD = 14;
	private const int DEFAULT_OVERBOUGHT = 69;
	private const int DEFAULT_OVERSOLD = 31;

	/** avgGain at the second-to-last position (base for recomputing the last value). */
	private float $baseAvgGain = 0.0;
	private float $baseAvgLoss = 0.0;

	/** Current avgGain/avgLoss for the last position (updated on every tick). */
	private float $lastAvgGain = 0.0;
	private float $lastAvgLoss = 0.0;

	/** @var float[] Incrementally maintained RSI values. */
	private array $rsiValues = [];
	/** @var int[] Corresponding timestamps. */
	private array $rsiTimestamps = [];
	/** @var string[] Corresponding signals. */
	private array $rsiSignals = [];

	private bool $initialized = false;

	public static function getName(): string {
		return 'RSI';
	}

	public function calculate(IMarket $market): IndicatorResult {
		$period = $this->parameters['period'] ?? self::DEFAULT_PERIOD;
		$overbought = $this->parameters['overbought'] ?? self::DEFAULT_OVERBOUGHT;
		$oversold = $this->parameters['oversold'] ?? self::DEFAULT_OVERSOLD;

		$candles = $market->getCandles();
		$n = count($candles);

		if ($n < $period + 1) {
			return new IndicatorResult([], [], []);
		}

		$newCandles = $this->syncPrices($candles);

		if (!$this->initialized) {
			$this->fullCalculate($period, $overbought, $oversold);
			$this->initialized = true;
		} elseif ($newCandles > 0) {
			if ($newCandles > 1) {
				$this->fullCalculate($period, $overbought, $oversold);
			} else {
				// Advance base state from the finalized previous value.
				$this->baseAvgGain = $this->lastAvgGain;
				$this->baseAvgLoss = $this->lastAvgLoss;

				$change = $this->closePrices[$n - 1] - $this->closePrices[$n - 2];
				$this->lastAvgGain = (($this->baseAvgGain * ($period - 1)) + max($change, 0)) / $period;
				$this->lastAvgLoss = (($this->baseAvgLoss * ($period - 1)) + max(-$change, 0)) / $period;

				$rsi = $this->computeRSI($this->lastAvgGain, $this->lastAvgLoss);
				$this->rsiValues[] = $rsi;
				$this->rsiTimestamps[] = $this->timestamps[$n - 1];
				$this->rsiSignals[] = self::classifySignal($rsi, $overbought, $oversold);
			}
		}

		// Partial candle update: recompute last RSI from base state + current close.
		$change = $this->closePrices[$n - 1] - $this->closePrices[$n - 2];
		$this->lastAvgGain = (($this->baseAvgGain * ($period - 1)) + max($change, 0)) / $period;
		$this->lastAvgLoss = (($this->baseAvgLoss * ($period - 1)) + max(-$change, 0)) / $period;

		$rsi = $this->computeRSI($this->lastAvgGain, $this->lastAvgLoss);
		$lastIdx = count($this->rsiValues) - 1;
		$this->rsiValues[$lastIdx] = $rsi;
		$this->rsiSignals[$lastIdx] = self::classifySignal($rsi, $overbought, $oversold);

		return new IndicatorResult($this->rsiValues, $this->rsiTimestamps, $this->rsiSignals);
	}

	/**
	 * Full calculation with base-state extraction.
	 */
	private function fullCalculate(int $period, float $overbought, float $oversold): void {
		$prices = $this->closePrices;
		$count = count($prices);

		$changes = [];
		for ($i = 1; $i < $count; $i++) {
			$changes[] = $prices[$i] - $prices[$i - 1];
		}

		$sumGain = 0.0;
		$sumLoss = 0.0;
		for ($i = 0; $i < $period; $i++) {
			if ($changes[$i] > 0) $sumGain += $changes[$i];
			else $sumLoss += abs($changes[$i]);
		}
		$avgGain = $sumGain / $period;
		$avgLoss = $sumLoss / $period;

		$this->rsiValues = [];
		$this->rsiTimestamps = [];
		$this->rsiSignals = [];

		$rsi = $this->computeRSI($avgGain, $avgLoss);
		$this->rsiValues[] = $rsi;
		$this->rsiTimestamps[] = $this->timestamps[$period];
		$this->rsiSignals[] = self::classifySignal($rsi, $overbought, $oversold);

		$prevAvgGain = $avgGain;
		$prevAvgLoss = $avgLoss;

		for ($i = $period; $i < count($changes); $i++) {
			$c = $changes[$i];
			$avgGain = (($prevAvgGain * ($period - 1)) + max($c, 0)) / $period;
			$avgLoss = (($prevAvgLoss * ($period - 1)) + max(-$c, 0)) / $period;

			$rsi = $this->computeRSI($avgGain, $avgLoss);
			$this->rsiValues[] = $rsi;
			$this->rsiTimestamps[] = $this->timestamps[$i + 1];
			$this->rsiSignals[] = self::classifySignal($rsi, $overbought, $oversold);

			// Track base state: the value at the second-to-last position.
			if ($i === count($changes) - 2) {
				$this->baseAvgGain = $avgGain;
				$this->baseAvgLoss = $avgLoss;
			}

			$prevAvgGain = $avgGain;
			$prevAvgLoss = $avgLoss;
		}

		$this->lastAvgGain = $prevAvgGain;
		$this->lastAvgLoss = $prevAvgLoss;

		// When there are exactly period+1 prices, there's only 1 RSI value.
		if (count($changes) <= $period) {
			$this->baseAvgGain = $avgGain;
			$this->baseAvgLoss = $avgLoss;
		}
	}

	private function computeRSI(float $avgGain, float $avgLoss): float {
		if ($avgLoss == 0.0) {
			return 100.0;
		}
		return 100.0 - (100.0 / (1.0 + $avgGain / $avgLoss));
	}

	private static function classifySignal(float $rsi, float $overbought, float $oversold): string {
		if ($rsi >= $overbought) return 'overbought';
		if ($rsi <= $oversold) return 'oversold';
		return 'neutral';
	}

	protected function resetState(): void {
		parent::resetState();
		$this->baseAvgGain = 0.0;
		$this->baseAvgLoss = 0.0;
		$this->lastAvgGain = 0.0;
		$this->lastAvgLoss = 0.0;
		$this->rsiValues = [];
		$this->rsiTimestamps = [];
		$this->rsiSignals = [];
		$this->initialized = false;
	}

	/**
	 * Calculate RSI from an array of close prices (stateless, used by strategies directly).
	 */
	public static function calculateFromPrices(array $closePrices, int $period = self::DEFAULT_PERIOD): array {
		$count = count($closePrices);
		if ($count < $period + 1) {
			return [];
		}

		$rsi = [];
		$changes = [];
		for ($i = 1; $i < $count; $i++) {
			$changes[] = $closePrices[$i] - $closePrices[$i - 1];
		}

		$gains = array_map(fn($c) => $c > 0 ? $c : 0, array_slice($changes, 0, $period));
		$losses = array_map(fn($c) => $c < 0 ? abs($c) : 0, array_slice($changes, 0, $period));

		$avgGain = array_sum($gains) / $period;
		$avgLoss = array_sum($losses) / $period;

		$rsi[] = $avgLoss == 0 ? 100.0 : 100 - (100 / (1 + $avgGain / $avgLoss));

		for ($i = $period; $i < count($changes); $i++) {
			$change = $changes[$i];
			$avgGain = (($avgGain * ($period - 1)) + ($change > 0 ? $change : 0)) / $period;
			$avgLoss = (($avgLoss * ($period - 1)) + ($change < 0 ? abs($change) : 0)) / $period;
			$rsi[] = $avgLoss == 0 ? 100.0 : 100 - (100 / (1 + $avgGain / $avgLoss));
		}

		return $rsi;
	}
}
