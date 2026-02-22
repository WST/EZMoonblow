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
 * Incremental: maintains three EMA base values (fast, slow, signal) so each
 * tick costs O(1) instead of O(3n).
 */
class MACD extends AbstractIndicator
{
	private const int DEFAULT_FAST_PERIOD = 12;
	private const int DEFAULT_SLOW_PERIOD = 26;
	private const int DEFAULT_SIGNAL_PERIOD = 9;

	private float $baseFastEMA = 0.0;
	private float $baseSlowEMA = 0.0;
	private float $baseSignalEMA = 0.0;

	private float $lastFastEMA = 0.0;
	private float $lastSlowEMA = 0.0;

	/** @var float[] MACD line values (trimmed to signal length). */
	private array $macdValues = [];
	/** @var float[] Signal line values. */
	private array $signalValues = [];
	/** @var float[] Histogram values. */
	private array $histogramValues = [];
	/** @var int[] Timestamps aligned with the output. */
	private array $macdTimestamps = [];

	private bool $initialized = false;

	public static function getName(): string {
		return 'MACD';
	}

	public function calculate(IMarket $market): IndicatorResult {
		$fastPeriod = (int)($this->parameters['fastPeriod'] ?? self::DEFAULT_FAST_PERIOD);
		$slowPeriod = (int)($this->parameters['slowPeriod'] ?? self::DEFAULT_SLOW_PERIOD);
		$signalPeriod = (int)($this->parameters['signalPeriod'] ?? self::DEFAULT_SIGNAL_PERIOD);

		$candles = $market->getCandles();
		$n = count($candles);
		$minRequired = $slowPeriod + $signalPeriod - 1;

		if ($n < $minRequired) {
			return new IndicatorResult([], [], []);
		}

		$newCandles = $this->syncPrices($candles);
		$kFast = 2.0 / ($fastPeriod + 1);
		$kSlow = 2.0 / ($slowPeriod + 1);
		$kSignal = 2.0 / ($signalPeriod + 1);

		if (!$this->initialized) {
			$this->fullCalculate($fastPeriod, $slowPeriod, $signalPeriod);
			$this->initialized = true;
		} elseif ($newCandles > 0) {
			if ($newCandles > 1) {
				$this->fullCalculate($fastPeriod, $slowPeriod, $signalPeriod);
			} else {
				// Advance base states from the finalized previous values.
				$this->baseFastEMA = $this->lastFastEMA;
				$this->baseSlowEMA = $this->lastSlowEMA;
				$this->baseSignalEMA = $this->signalValues[count($this->signalValues) - 1];

				$close = $this->closePrices[$n - 1];
				$this->lastFastEMA = $close * $kFast + $this->baseFastEMA * (1 - $kFast);
				$this->lastSlowEMA = $close * $kSlow + $this->baseSlowEMA * (1 - $kSlow);

				$macd = $this->lastFastEMA - $this->lastSlowEMA;
				$signal = $macd * $kSignal + $this->baseSignalEMA * (1 - $kSignal);

				$this->macdValues[] = $macd;
				$this->signalValues[] = $signal;
				$this->histogramValues[] = $macd - $signal;
				$this->macdTimestamps[] = $this->timestamps[$n - 1];
			}
		}

		// Partial candle update: recompute last values from base states.
		$close = $this->closePrices[$n - 1];
		$this->lastFastEMA = $close * $kFast + $this->baseFastEMA * (1 - $kFast);
		$this->lastSlowEMA = $close * $kSlow + $this->baseSlowEMA * (1 - $kSlow);

		$macd = $this->lastFastEMA - $this->lastSlowEMA;
		$signal = $macd * $kSignal + $this->baseSignalEMA * (1 - $kSignal);

		$lastIdx = count($this->macdValues) - 1;
		$this->macdValues[$lastIdx] = $macd;
		$this->signalValues[$lastIdx] = $signal;
		$this->histogramValues[$lastIdx] = $macd - $signal;

		return new IndicatorResult(
			$this->macdValues,
			$this->macdTimestamps,
			$this->histogramValues,
		);
	}

	/**
	 * Full calculation with base-state extraction.
	 */
	private function fullCalculate(int $fastPeriod, int $slowPeriod, int $signalPeriod): void {
		$prices = $this->closePrices;
		$count = count($prices);

		$emaFast = EMA::calculateFromPrices($prices, $fastPeriod);
		$emaSlow = EMA::calculateFromPrices($prices, $slowPeriod);

		if (empty($emaFast) || empty($emaSlow)) {
			$this->macdValues = [];
			$this->signalValues = [];
			$this->histogramValues = [];
			$this->macdTimestamps = [];
			return;
		}

		$fastOffset = $slowPeriod - $fastPeriod;
		$alignedFast = array_slice($emaFast, $fastOffset);
		$macdLine = [];
		$len = min(count($alignedFast), count($emaSlow));
		for ($i = 0; $i < $len; $i++) {
			$macdLine[] = $alignedFast[$i] - $emaSlow[$i];
		}

		if (count($macdLine) < $signalPeriod) {
			$this->macdValues = [];
			$this->signalValues = [];
			$this->histogramValues = [];
			$this->macdTimestamps = [];
			return;
		}

		$signalLine = EMA::calculateFromPrices($macdLine, $signalPeriod);
		if (empty($signalLine)) {
			$this->macdValues = [];
			$this->signalValues = [];
			$this->histogramValues = [];
			$this->macdTimestamps = [];
			return;
		}

		$macdTrimmed = array_slice($macdLine, count($macdLine) - count($signalLine));
		$histogram = [];
		for ($i = 0; $i < count($signalLine); $i++) {
			$histogram[] = $macdTrimmed[$i] - $signalLine[$i];
		}

		$this->macdValues = $macdTrimmed;
		$this->signalValues = $signalLine;
		$this->histogramValues = $histogram;

		$signalLength = count($signalLine);
		$this->macdTimestamps = array_slice($this->timestamps, count($this->timestamps) - $signalLength);

		// Extract base EMA states (second-to-last positions).
		$fastCount = count($emaFast);
		$slowCount = count($emaSlow);
		$this->baseFastEMA = $fastCount >= 2 ? $emaFast[$fastCount - 2] : $emaFast[0];
		$this->baseSlowEMA = $slowCount >= 2 ? $emaSlow[$slowCount - 2] : $emaSlow[0];
		$this->lastFastEMA = $emaFast[$fastCount - 1];
		$this->lastSlowEMA = $emaSlow[$slowCount - 1];

		$sigCount = count($signalLine);
		$this->baseSignalEMA = $sigCount >= 2 ? $signalLine[$sigCount - 2] : $signalLine[0];
	}

	protected function resetState(): void {
		parent::resetState();
		$this->baseFastEMA = 0.0;
		$this->baseSlowEMA = 0.0;
		$this->baseSignalEMA = 0.0;
		$this->lastFastEMA = 0.0;
		$this->lastSlowEMA = 0.0;
		$this->macdValues = [];
		$this->signalValues = [];
		$this->histogramValues = [];
		$this->macdTimestamps = [];
		$this->initialized = false;
	}

	/**
	 * Calculate MACD from an array of close prices (stateless, used by strategies directly).
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

		$signalLine = EMA::calculateFromPrices($macdLine, $signalPeriod);
		if (empty($signalLine)) {
			return $empty;
		}

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
