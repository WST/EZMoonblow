<?php

namespace Izzy\Strategies\EZMoonblowSE;

use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\IMarket;
use Izzy\Strategies\EZMoonblowSE\Parameters\CooldownCandles;
use Izzy\Strategies\EZMoonblowSE\Parameters\RSILongThreshold;
use Izzy\Strategies\EZMoonblowSE\Parameters\RSIShortThreshold;
use Izzy\System\Logger;

/**
 * Single-entry RSI-based strategy.
 *
 * Enters long when RSI crosses below the long threshold (oversold),
 * enters short when RSI crosses above the short threshold (overbought).
 * No trend filter — pure mean-reversion on extreme RSI values.
 *
 * Long entry:  RSI crosses below rsiLongThreshold (e.g. 30)
 * Short entry: RSI crosses above rsiShortThreshold (e.g. 70)
 */
class EZMoonblowSE extends AbstractSingleEntryStrategy
{
	public static function getDisplayName(): string {
		return 'RSI Single Entry';
	}

	/** RSI threshold for long entries (RSI crosses below = oversold). */
	private float $rsiLongThreshold;

	/** RSI threshold for short entries (RSI crosses above = overbought). */
	private float $rsiShortThreshold;

	/** Minimum candles to wait between entries (prevents whipsaw). */
	private int $cooldownCandles;

	/** Candle open-time of the last entry signal (cooldown tracker). */
	private int $lastEntryTime = 0;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->rsiLongThreshold = (float)($params['rsiLongThreshold'] ?? 30);
		$this->rsiShortThreshold = (float)($params['rsiShortThreshold'] ?? 70);
		$this->cooldownCandles = (int)($params['cooldownCandles'] ?? 0);
	}

	/**
	 * @inheritDoc
	 */
	public function useIndicators(): array {
		return [RSI::class];
	}

	// ------------------------------------------------------------------
	// Entry signal detection
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	protected function detectLongSignal(): bool {
		if (!$this->cooldownElapsed()) {
			return false;
		}

		if (!$this->rsiCrossesBelow($this->rsiLongThreshold)) {
			return false;
		}

		$this->markEntry();
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function detectShortSignal(): bool {
		if (!$this->cooldownElapsed()) {
			return false;
		}

		if (!$this->rsiCrossesAbove($this->rsiShortThreshold)) {
			return false;
		}

		$this->markEntry();
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function doesLong(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function doesShort(): bool {
		return true;
	}

	// ------------------------------------------------------------------
	// Cooldown logic
	// ------------------------------------------------------------------

	/**
	 * Check if enough time has passed since the last entry.
	 *
	 * @return bool True if cooldown has elapsed (or is disabled).
	 */
	private function cooldownElapsed(): bool {
		if ($this->cooldownCandles <= 0) {
			return true;
		}
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return true;
		}
		$currentTime = (int)end($candles)->getOpenTime();
		$cooldownSeconds = $this->cooldownCandles * $this->market->getPair()->getTimeframe()->toSeconds();
		return $this->lastEntryTime === 0
			|| ($currentTime - $this->lastEntryTime) >= $cooldownSeconds;
	}

	/**
	 * Record the current candle time as the last entry moment.
	 */
	private function markEntry(): void {
		$candles = $this->market->getCandles();
		if (!empty($candles)) {
			$this->lastEntryTime = (int)end($candles)->getOpenTime();
		}
	}

	// ------------------------------------------------------------------
	// RSI crossover detection
	// ------------------------------------------------------------------

	/**
	 * Detect RSI crossing above a threshold.
	 *
	 * @param float $threshold RSI threshold to cross above.
	 * @return bool True if RSI just crossed above the threshold.
	 */
	private function rsiCrossesAbove(float $threshold): bool {
		$log = Logger::getLogger();
		$result = $this->market->getIndicatorResult(RSI::getName());
		if (is_null($result)) {
			$log->debug("[RSI↑] No RSI data available");
			return false;
		}
		$values = $result->getValues();
		$count = count($values);
		if ($count < 2) {
			$log->debug("[RSI↑] Not enough RSI values (count=$count)");
			return false;
		}
		$previous = $values[$count - 2];
		$current = $values[$count - 1];
		$cross = $previous < $threshold && $current >= $threshold;
		$log->debug(sprintf(
			"[RSI↑] prev=%.2f → cur=%.2f | threshold=%.1f | cross=%s",
			$previous, $current, $threshold, $cross ? 'YES — SIGNAL!' : 'no'
		));
		return $cross;
	}

	/**
	 * Detect RSI crossing below a threshold.
	 *
	 * @param float $threshold RSI threshold to cross below.
	 * @return bool True if RSI just crossed below the threshold.
	 */
	private function rsiCrossesBelow(float $threshold): bool {
		$log = Logger::getLogger();
		$result = $this->market->getIndicatorResult(RSI::getName());
		if (is_null($result)) {
			$log->debug("[RSI↓] No RSI data available");
			return false;
		}
		$values = $result->getValues();
		$count = count($values);
		if ($count < 2) {
			$log->debug("[RSI↓] Not enough RSI values (count=$count)");
			return false;
		}
		$previous = $values[$count - 2];
		$current = $values[$count - 1];
		$cross = $previous > $threshold && $current <= $threshold;
		$log->debug(sprintf(
			"[RSI↓] prev=%.2f → cur=%.2f | threshold=%.1f | cross=%s",
			$previous, $current, $threshold, $cross ? 'YES — SIGNAL!' : 'no'
		));
		return $cross;
	}

	// ------------------------------------------------------------------
	// Parameter definitions
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function getParameters(): array {
		return array_merge(parent::getParameters(), [
			new RSILongThreshold('30'),
			new RSIShortThreshold('70'),
			new CooldownCandles(),
		]);
	}
}
