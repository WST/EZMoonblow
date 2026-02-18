<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

/**
 * MACD (Moving Average Convergence Divergence) indicator.
 *
 * Consists of three components:
 *   - MACD Line   = EMA(fast) - EMA(slow)
 *   - Signal Line = EMA(signalPeriod) of the MACD Line
 *   - Histogram   = MACD Line - Signal Line
 *
 * A bullish signal occurs when the MACD Line crosses above the Signal Line;
 * a bearish signal occurs when it crosses below.
 */
class MACD extends AbstractIndicator
{
	private const int DEFAULT_FAST_PERIOD = 12;
	private const int DEFAULT_SLOW_PERIOD = 26;
	private const int DEFAULT_SIGNAL_PERIOD = 9;

	public static function getName(): string {
		return 'MACD';
	}

	/**
	 * Calculate MACD for the given market using indicator-system parameters.
	 *
	 * The result packs MACD Line into values, timestamps, and signal/histogram
	 * into the signals array as associative entries.
	 *
	 * @param IMarket $market Market with candle data.
	 * @return IndicatorResult MACD calculation result.
	 */
	public function calculate(IMarket $market): IndicatorResult {
		$fastPeriod = (int)($this->parameters['fastPeriod'] ?? self::DEFAULT_FAST_PERIOD);
		$slowPeriod = (int)($this->parameters['slowPeriod'] ?? self::DEFAULT_SLOW_PERIOD);
		$signalPeriod = (int)($this->parameters['signalPeriod'] ?? self::DEFAULT_SIGNAL_PERIOD);

		$candles = $market->getCandles();
		$minRequired = $slowPeriod + $signalPeriod - 1;
		if (count($candles) < $minRequired) {
			return new IndicatorResult([], [], []);
		}

		$closePrices = $this->getClosePrices($candles);
		$timestamps = $this->getTimestamps($candles);

		$result = self::calculateFromPrices($closePrices, $fastPeriod, $slowPeriod, $signalPeriod);
		if (empty($result['macd'])) {
			return new IndicatorResult([], [], []);
		}

		// Trim timestamps to match signal/histogram length (shortest output).
		$signalLength = count($result['signal']);
		$trimmedTimestamps = array_slice($timestamps, count($timestamps) - $signalLength);

		return new IndicatorResult(
			array_slice($result['macd'], count($result['macd']) - $signalLength),
			$trimmedTimestamps,
			$result['histogram'],
		);
	}

	/**
	 * Calculate MACD from an array of close prices.
	 *
	 * Can be called directly by strategies for custom parameter combinations
	 * without going through the indicator system.
	 *
	 * @param float[] $closePrices Close prices (chronological order).
	 * @param int $fastPeriod Fast EMA period (typically 12).
	 * @param int $slowPeriod Slow EMA period (typically 26).
	 * @param int $signalPeriod Signal EMA period (typically 9).
	 * @return array{macd: float[], signal: float[], histogram: float[]}
	 */
	public static function calculateFromPrices(
		array $closePrices,
		int $fastPeriod = 12,
		int $slowPeriod = 26,
		int $signalPeriod = 9,
	): array {
		$empty = ['macd' => [], 'signal' => [], 'histogram' => []];

		$emaFast = EMA::calculateFromPrices($closePrices, $fastPeriod);
		$emaSlow = EMA::calculateFromPrices($closePrices, $slowPeriod);

		if (empty($emaFast) || empty($emaSlow)) {
			return $empty;
		}

		// Align: EMA fast starts at index (fastPeriod-1), EMA slow at (slowPeriod-1).
		// MACD Line values exist from index (slowPeriod-1) onward.
		// The fast EMA array is longer; trim its beginning to match the slow EMA length.
		$fastOffset = $slowPeriod - $fastPeriod;
		$alignedFast = array_slice($emaFast, $fastOffset);
		$macdLine = [];
		$len = min(count($alignedFast), count($emaSlow));
		for ($i = 0; $i < $len; $i++) {
			$macdLine[] = $alignedFast[$i] - $emaSlow[$i];
		}

		if (count($macdLine) < $signalPeriod) {
			return $empty;
		}

		// Signal Line = EMA of the MACD Line.
		$signalLine = EMA::calculateFromPrices($macdLine, $signalPeriod);
		if (empty($signalLine)) {
			return $empty;
		}

		// Histogram = MACD - Signal (aligned to signal length).
		$macdTrimmed = array_slice($macdLine, count($macdLine) - count($signalLine));
		$histogram = [];
		for ($i = 0; $i < count($signalLine); $i++) {
			$histogram[] = $macdTrimmed[$i] - $signalLine[$i];
		}

		return [
			'macd' => $macdTrimmed,
			'signal' => $signalLine,
			'histogram' => $histogram,
		];
	}
}
