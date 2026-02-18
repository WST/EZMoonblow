<?php

namespace Izzy\Strategies\EZMoonblowSEMACD;

use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Indicators\ADX;
use Izzy\Indicators\MACD;
use Izzy\Interfaces\IMarket;
use Izzy\Strategies\EZMoonblowSEMACD\Parameters\ADXFilter;
use Izzy\Strategies\EZMoonblowSEMACD\Parameters\ADXPeriod;
use Izzy\Strategies\EZMoonblowSEMACD\Parameters\ADXThreshold;
use Izzy\Strategies\EZMoonblowSEMACD\Parameters\CooldownCandles;
use Izzy\Strategies\EZMoonblowSEMACD\Parameters\MACDFastPeriod;
use Izzy\Strategies\EZMoonblowSEMACD\Parameters\MACDSignalPeriod;
use Izzy\Strategies\EZMoonblowSEMACD\Parameters\MACDSlowPeriod;
use Izzy\System\Logger;

/**
 * Single-entry trend-following strategy using MACD crossover.
 *
 * The core signal is the classic MACD crossover:
 *   - Long when MACD Line crosses above the Signal Line (bullish momentum)
 *   - Short when MACD Line crosses below the Signal Line (bearish momentum)
 *
 * Two optional filters can be enabled independently:
 *
 * 1. EMA daily trend filter (same concept as in EZMoonblowSEBoll):
 *    Requires the daily close to be above/below a slow EMA to confirm
 *    the macro trend direction before entering.
 *
 * 2. ADX trend strength filter:
 *    Requires ADX to be above a configurable threshold (default 20),
 *    confirming that the market is actually trending (not range-bound)
 *    before acting on the MACD crossover.
 */
class EZMoonblowSEMACD extends AbstractSingleEntryStrategy
{
	public static function getDisplayName(): string {
		return 'MACD Single Entry';
	}

	/** MACD fast EMA period. */
	private int $macdFastPeriod;

	/** MACD slow EMA period. */
	private int $macdSlowPeriod;

	/** MACD signal EMA period. */
	private int $macdSignalPeriod;

	/** Whether to use the ADX trend strength filter. */
	private bool $adxFilter;

	/** ADX period. */
	private int $adxPeriod;

	/** ADX threshold — entries only when ADX > this value. */
	private float $adxThreshold;

	/** Minimum candles to wait between entries (prevents whipsaw). */
	private int $cooldownCandles;

	/** Candle open-time of the last entry signal (cooldown tracker). */
	private int $lastEntryTime = 0;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->macdFastPeriod = (int)($params['macdFastPeriod'] ?? 12);
		$this->macdSlowPeriod = (int)($params['macdSlowPeriod'] ?? 26);
		$this->macdSignalPeriod = (int)($params['macdSignalPeriod'] ?? 9);
		$this->adxFilter = in_array(strtolower($params['adxFilter'] ?? 'false'), ['yes', 'true', '1'], true);
		$this->adxPeriod = (int)($params['adxPeriod'] ?? 14);
		$this->adxThreshold = (float)($params['adxThreshold'] ?? 20.0);
		$this->cooldownCandles = (int)($params['cooldownCandles'] ?? 0);
	}

	/**
	 * @inheritDoc
	 *
	 * MACD and ADX are calculated directly via calculateFromPrices()
	 * to use strategy-specific parameters. No need to register them
	 * through the indicator system.
	 */
	public function useIndicators(): array {
		return [];
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

		if ($this->adxFilter && !$this->adxAboveThreshold()) {
			return false;
		}

		if (!$this->macdCrossesAboveSignal()) {
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

		if ($this->adxFilter && !$this->adxAboveThreshold()) {
			return false;
		}

		if (!$this->macdCrossesBelowSignal()) {
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
	// MACD crossover detection
	// ------------------------------------------------------------------

	/**
	 * Calculate MACD components from the current market candles.
	 *
	 * @return array{macd: float[], signal: float[], histogram: float[]}|null
	 */
	private function calculateMACD(): ?array {
		$candles = $this->market->getCandles();
		$minRequired = $this->macdSlowPeriod + $this->macdSignalPeriod - 1;
		if (count($candles) < $minRequired) {
			return null;
		}
		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);
		$result = MACD::calculateFromPrices(
			$closePrices,
			$this->macdFastPeriod,
			$this->macdSlowPeriod,
			$this->macdSignalPeriod,
		);
		if (count($result['macd']) < 2) {
			return null;
		}
		return $result;
	}

	/**
	 * Detect MACD Line crossing above the Signal Line (bullish crossover).
	 */
	private function macdCrossesAboveSignal(): bool {
		$log = Logger::getLogger();
		$data = $this->calculateMACD();
		if ($data === null) {
			$log->debug("[MACD↑] Not enough data for MACD calculation");
			return false;
		}

		$macd = $data['macd'];
		$signal = $data['signal'];
		$count = count($macd);

		$prevMACD = $macd[$count - 2];
		$currMACD = $macd[$count - 1];
		$prevSignal = $signal[$count - 2];
		$currSignal = $signal[$count - 1];

		$cross = $prevMACD <= $prevSignal && $currMACD > $currSignal;

		$log->debug(sprintf(
			"[MACD↑] prevMACD=%.8f prevSignal=%.8f | currMACD=%.8f currSignal=%.8f | cross=%s",
			$prevMACD, $prevSignal, $currMACD, $currSignal,
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	/**
	 * Detect MACD Line crossing below the Signal Line (bearish crossover).
	 */
	private function macdCrossesBelowSignal(): bool {
		$log = Logger::getLogger();
		$data = $this->calculateMACD();
		if ($data === null) {
			$log->debug("[MACD↓] Not enough data for MACD calculation");
			return false;
		}

		$macd = $data['macd'];
		$signal = $data['signal'];
		$count = count($macd);

		$prevMACD = $macd[$count - 2];
		$currMACD = $macd[$count - 1];
		$prevSignal = $signal[$count - 2];
		$currSignal = $signal[$count - 1];

		$cross = $prevMACD >= $prevSignal && $currMACD < $currSignal;

		$log->debug(sprintf(
			"[MACD↓] prevMACD=%.8f prevSignal=%.8f | currMACD=%.8f currSignal=%.8f | cross=%s",
			$prevMACD, $prevSignal, $currMACD, $currSignal,
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	// ------------------------------------------------------------------
	// ADX trend strength filter
	// ------------------------------------------------------------------

	/**
	 * Check if ADX is above the configured threshold (trending market).
	 */
	private function adxAboveThreshold(): bool {
		$log = Logger::getLogger();
		$candles = $this->market->getCandles();

		if (count($candles) < $this->adxPeriod * 2 + 1) {
			$log->debug("[ADX] Not enough candles for ADX calculation");
			return false;
		}

		$highPrices = array_map(fn($c) => $c->getHighPrice(), $candles);
		$lowPrices = array_map(fn($c) => $c->getLowPrice(), $candles);
		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);

		$adxValues = ADX::calculateFromPrices($highPrices, $lowPrices, $closePrices, $this->adxPeriod);
		if (empty($adxValues)) {
			$log->debug("[ADX] ADX calculation returned empty");
			return false;
		}

		$latestADX = end($adxValues);
		$pass = $latestADX >= $this->adxThreshold;

		$log->debug(sprintf(
			"[ADX] latest=%.2f threshold=%.1f | %s",
			$latestADX, $this->adxThreshold,
			$pass ? 'PASS — trending' : 'FAIL — range-bound',
		));

		return $pass;
	}

	// ------------------------------------------------------------------
	// Cooldown logic
	// ------------------------------------------------------------------

	/**
	 * Check if enough time has passed since the last entry.
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
	// Parameter definitions
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function getParameters(): array {
		return array_merge(parent::getParameters(), [
			new MACDFastPeriod(),
			new MACDSlowPeriod(),
			new MACDSignalPeriod(),
			new ADXFilter(),
			new ADXPeriod(),
			new ADXThreshold(),
			new CooldownCandles(),
		]);
	}
}
