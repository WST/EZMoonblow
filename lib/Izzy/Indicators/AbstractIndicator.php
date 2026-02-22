<?php

namespace Izzy\Indicators;

use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;

/**
 * Abstract base class for all technical indicators.
 *
 * Provides common functionality: configuration access, cached price arrays
 * that are extended incrementally (avoiding O(n) array_map per tick), and
 * a candle-count tracker so subclasses can distinguish "new candle" from
 * "partial candle update" and implement O(1) incremental calculations.
 */
abstract class AbstractIndicator implements IIndicator
{
	/** @var array Configuration parameters for the indicator */
	protected array $parameters;

	/** @var IPair The trading pair this indicator is calculated for */
	protected IPair $pair;

	protected IMarket $market;

	// ── Incremental price cache ──────────────────────────────────────
	/** Number of candles seen on the previous calculate() call. */
	protected int $prevCandleCount = 0;

	/** @var float[] Cached close prices, extended incrementally. */
	protected array $closePrices = [];

	/** @var float[] Cached high prices, extended incrementally. */
	protected array $highPrices = [];

	/** @var float[] Cached low prices, extended incrementally. */
	protected array $lowPrices = [];

	/** @var int[] Cached timestamps (candle open time). */
	protected array $timestamps = [];

	public function __construct(IMarket $market, array $parameters) {
		$this->parameters = $parameters;
		$this->market = $market;
		$this->pair = $market->getPair();
	}

	public function getParameters(): array {
		return $this->parameters;
	}

	public function getPair(): IPair {
		return $this->pair;
	}

	/**
	 * Validate that enough data is available for calculation.
	 */
	protected function validateData(array $candles, int $requiredCount): bool {
		return count($candles) >= $requiredCount;
	}

	/**
	 * Get a parameter value with optional default.
	 */
	protected function getParameter(string $key, mixed $default = null): mixed {
		return $this->parameters[$key] ?? $default;
	}

	// ── Price cache helpers (used by subclasses for incremental logic) ─

	/**
	 * Synchronize cached price arrays with the current candle data.
	 *
	 * On the first call (or after a reset) the full arrays are built.
	 * On subsequent calls only the last candle is refreshed (partial candle
	 * update) and any truly new candles are appended.
	 *
	 * @return int Number of truly new candles added (0 when only the last
	 *             candle's data changed within the same candle count).
	 */
	protected function syncPrices(array $candles): int {
		$n = count($candles);

		if ($n === 0) {
			$this->resetState();
			return 0;
		}

		if ($n < $this->prevCandleCount) {
			$this->resetState();
		}

		if ($this->prevCandleCount === 0) {
			$this->closePrices = [];
			$this->highPrices = [];
			$this->lowPrices = [];
			$this->timestamps = [];
			for ($i = 0; $i < $n; $i++) {
				$this->closePrices[] = $candles[$i]->getClosePrice();
				$this->highPrices[] = $candles[$i]->getHighPrice();
				$this->lowPrices[] = $candles[$i]->getLowPrice();
				$this->timestamps[] = $candles[$i]->getOpenTime();
			}
			$newCandles = $n;
		} else {
			$lastIdx = $this->prevCandleCount - 1;
			$this->closePrices[$lastIdx] = $candles[$lastIdx]->getClosePrice();
			$this->highPrices[$lastIdx] = $candles[$lastIdx]->getHighPrice();
			$this->lowPrices[$lastIdx] = $candles[$lastIdx]->getLowPrice();

			$newCandles = $n - $this->prevCandleCount;
			for ($i = $this->prevCandleCount; $i < $n; $i++) {
				$this->closePrices[] = $candles[$i]->getClosePrice();
				$this->highPrices[] = $candles[$i]->getHighPrice();
				$this->lowPrices[] = $candles[$i]->getLowPrice();
				$this->timestamps[] = $candles[$i]->getOpenTime();
			}
		}

		$this->prevCandleCount = $n;
		return $newCandles;
	}

	/**
	 * Reset all cached state. Subclasses MUST call parent::resetState()
	 * and clear their own indicator-specific caches.
	 */
	protected function resetState(): void {
		$this->prevCandleCount = 0;
		$this->closePrices = [];
		$this->highPrices = [];
		$this->lowPrices = [];
		$this->timestamps = [];
	}

	// ── Legacy helpers (kept for static calculateFromPrices methods) ──

	/**
	 * Extract close prices from array of candles.
	 * @deprecated Use $this->closePrices after syncPrices() instead.
	 */
	protected function getClosePrices(array $candles): array {
		return array_map(fn($candle) => $candle->getClosePrice(), $candles);
	}

	/**
	 * Extract timestamps from array of candles.
	 * @deprecated Use $this->timestamps after syncPrices() instead.
	 */
	protected function getTimestamps(array $candles): array {
		return array_map(fn($candle) => $candle->getOpenTime(), $candles);
	}
}
