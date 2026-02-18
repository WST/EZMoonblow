<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * Relative Strength Index (RSI) indicator.
 * Measures the speed and magnitude of price changes to identify overbought or oversold conditions.
 */
class RSI extends AbstractIndicator
{
	/**
	 * Default RSI period.
	 */
	private const int DEFAULT_PERIOD = 14;

	/**
	 * Default overbought threshold.
	 */
	private const int DEFAULT_OVERBOUGHT = 69;

	/**
	 * Default oversold threshold.
	 */
	private const int DEFAULT_OVERSOLD = 31;

	/**
	 * Get indicator name.
	 *
	 * @return string Indicator name.
	 */
	public static function getName(): string {
		return 'RSI';
	}

	/**
	 * Calculate RSI values for the given market.
	 *
	 * @param IMarket $market Market with candle data.
	 * @return IndicatorResult RSI calculation result.
	 */
	public function calculate(IMarket $market): IndicatorResult {
		$period = $this->parameters['period'] ?? self::DEFAULT_PERIOD;
		$overbought = $this->parameters['overbought'] ?? self::DEFAULT_OVERBOUGHT;
		$oversold = $this->parameters['oversold'] ?? self::DEFAULT_OVERSOLD;

		$candles = $market->getCandles();
		if (count($candles) < $period + 1) {
			return new IndicatorResult([], [], []);
		}

		$closePrices = $this->getClosePrices($candles);
		$timestamps = $this->getTimestamps($candles);

		$rsiValues = $this->calculateRSI($closePrices, $period);
		$signals = $this->generateSignals($rsiValues, $overbought, $oversold);

		// Adjust timestamps to match RSI values (skip first period)
		$rsiTimestamps = array_slice($timestamps, $period);

		return new IndicatorResult($rsiValues, $rsiTimestamps, $signals);
	}

	/**
	 * Calculate RSI values directly from an array of close prices.
	 * Convenience method for strategies that compute RSI without
	 * going through the indicator system.
	 *
	 * @param float[] $closePrices Array of close prices.
	 * @param int $period RSI period (default 14).
	 * @return float[] Array of RSI values.
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

	/**
	 * Calculate RSI values from close prices.
	 *
	 * @param array $prices Array of close prices.
	 * @param int $period RSI period.
	 * @return array Array of RSI values.
	 */
	private function calculateRSI(array $prices, int $period): array {
		$rsi = [];
		$count = count($prices);

		if ($count < $period + 1) {
			return $rsi;
		}

		// Calculate price changes
		$changes = [];
		for ($i = 1; $i < $count; $i++) {
			$changes[] = $prices[$i] - $prices[$i - 1];
		}

		// Calculate initial average gain and loss
		$gains = array_map(fn($change) => $change > 0 ? $change : 0, array_slice($changes, 0, $period));
		$losses = array_map(fn($change) => $change < 0 ? abs($change) : 0, array_slice($changes, 0, $period));

		$avgGain = array_sum($gains) / $period;
		$avgLoss = array_sum($losses) / $period;

		// Calculate first RSI value
		if ($avgLoss == 0) {
			$rsi[] = 100;
		} else {
			$rs = $avgGain / $avgLoss;
			$rsi[] = 100 - (100 / (1 + $rs));
		}

		// Calculate subsequent RSI values using smoothed averages
		for ($i = $period; $i < count($changes); $i++) {
			$change = $changes[$i];
			$gain = $change > 0 ? $change : 0;
			$loss = $change < 0 ? abs($change) : 0;

			$avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
			$avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;

			if ($avgLoss == 0) {
				$rsi[] = 100;
			} else {
				$rs = $avgGain / $avgLoss;
				$rsi[] = 100 - (100 / (1 + $rs));
			}
		}

		return $rsi;
	}

	/**
	 * Generate signals based on RSI values.
	 *
	 * @param array $rsiValues Array of RSI values.
	 * @param float $overbought Overbought threshold.
	 * @param float $oversold Oversold threshold.
	 * @return array Array of signals.
	 */
	private function generateSignals(array $rsiValues, float $overbought, float $oversold): array {
		$signals = [];

		foreach ($rsiValues as $rsi) {
			if ($rsi >= $overbought) {
				$signals[] = 'overbought';
			} elseif ($rsi <= $oversold) {
				$signals[] = 'oversold';
			} else {
				$signals[] = 'neutral';
			}
		}

		return $signals;
	}
}
