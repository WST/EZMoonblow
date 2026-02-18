<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku;

use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Indicators\Ichimoku;
use Izzy\Interfaces\IMarket;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\ChikouFilter;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\CooldownCandles;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\Displacement;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\KijunPeriod;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\KumoFilter;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\ReverseSignals;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\SenkouBPeriod;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\SignalType;
use Izzy\Strategies\EZMoonblowSEIchimoku\Parameters\TenkanPeriod;
use Izzy\System\Logger;

/**
 * Single-entry strategy based on Ichimoku Cloud (Ichimoku Kinko Hyo).
 *
 * Supports two entry signal modes (selected via the signalType parameter):
 *
 * 1. TK Cross (default):
 *    - Long when Tenkan-sen crosses above Kijun-sen
 *    - Short when Tenkan-sen crosses below Kijun-sen
 *
 * 2. Kumo Breakout:
 *    - Long when price crosses above the upper edge of the cloud
 *    - Short when price crosses below the lower edge of the cloud
 *
 * Two optional filters can be enabled independently:
 *
 * - Kumo Filter (only for TK Cross mode): requires price to be on the
 *   correct side of the cloud (above for longs, below for shorts).
 *   This eliminates weak TK crosses that occur inside or against the cloud.
 *
 * - Chikou Span confirmation: requires the current close to be above
 *   (for longs) or below (for shorts) the close from displacement bars ago.
 *   This confirms momentum direction.
 *
 * A "reverse signals" option is available to swap long/short signals.
 * Classic Ichimoku is trend-following and works best on daily timeframes.
 * On lower timeframes (1h, 4h) the market is more mean-reverting, so
 * the reversed signals often outperform the original ones.
 */
class EZMoonblowSEIchimoku extends AbstractSingleEntryStrategy
{
	public static function getDisplayName(): string {
		return 'Ichimoku Cloud Single Entry';
	}

	private const string SIGNAL_TK_CROSS = 'tk_cross';
	private const string SIGNAL_KUMO_BREAKOUT = 'kumo_breakout';

	/** Entry signal type: 'tk_cross' or 'kumo_breakout'. */
	private string $signalType;

	/** Tenkan-sen (Conversion Line) period. */
	private int $tenkanPeriod;

	/** Kijun-sen (Base Line) period. */
	private int $kijunPeriod;

	/** Senkou Span B period. */
	private int $senkouBPeriod;

	/** Cloud displacement / Chikou shift. */
	private int $displacement;

	/** Whether to require price on the correct side of the cloud (TK Cross mode). */
	private bool $kumoFilter;

	/** Whether to require Chikou Span confirmation. */
	private bool $chikouFilter;

	/** Whether to swap long/short signals (mean-reversion mode). */
	private bool $reverseSignals;

	/** Minimum candles to wait between entries (prevents whipsaw). */
	private int $cooldownCandles;

	/** Candle open-time of the last entry signal (cooldown tracker). */
	private int $lastEntryTime = 0;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->signalType = ($params['signalType'] ?? self::SIGNAL_TK_CROSS);
		$this->tenkanPeriod = (int)($params['tenkanPeriod'] ?? 9);
		$this->kijunPeriod = (int)($params['kijunPeriod'] ?? 26);
		$this->senkouBPeriod = (int)($params['senkouBPeriod'] ?? 52);
		$this->displacement = (int)($params['displacement'] ?? 26);
		$this->kumoFilter = in_array(strtolower($params['kumoFilter'] ?? 'false'), ['yes', 'true', '1'], true);
		$this->chikouFilter = in_array(strtolower($params['chikouFilter'] ?? 'false'), ['yes', 'true', '1'], true);
		$this->reverseSignals = in_array(strtolower($params['reverseSignals'] ?? 'false'), ['yes', 'true', '1'], true);
		$this->cooldownCandles = (int)($params['cooldownCandles'] ?? 0);
	}

	/**
	 * @inheritDoc
	 *
	 * Ichimoku is calculated directly via calculateFromPrices()
	 * to use strategy-specific parameters.
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
		return $this->reverseSignals
			? $this->detectBearishSignal()
			: $this->detectBullishSignal();
	}

	/**
	 * @inheritDoc
	 */
	protected function detectShortSignal(): bool {
		return $this->reverseSignals
			? $this->detectBullishSignal()
			: $this->detectBearishSignal();
	}

	/**
	 * Detect a bullish Ichimoku signal (classic: go long).
	 */
	private function detectBullishSignal(): bool {
		if (!$this->cooldownElapsed()) {
			return false;
		}

		$data = $this->calculateIchimoku();
		if ($data === null) {
			return false;
		}

		$candles = $this->market->getCandles();
		$idx = count($candles) - 1;

		if ($this->signalType === self::SIGNAL_TK_CROSS) {
			if (!$this->tenkanCrossesAboveKijun($data, $idx)) {
				return false;
			}
			if ($this->kumoFilter && !$this->priceAboveCloud($data, $idx)) {
				return false;
			}
		} else {
			if (!$this->priceCrossesAboveCloud($data, $idx)) {
				return false;
			}
		}

		if ($this->chikouFilter && !$this->chikouConfirmsLong($data, $idx)) {
			return false;
		}

		$this->markEntry();
		return true;
	}

	/**
	 * Detect a bearish Ichimoku signal (classic: go short).
	 */
	private function detectBearishSignal(): bool {
		if (!$this->cooldownElapsed()) {
			return false;
		}

		$data = $this->calculateIchimoku();
		if ($data === null) {
			return false;
		}

		$candles = $this->market->getCandles();
		$idx = count($candles) - 1;

		if ($this->signalType === self::SIGNAL_TK_CROSS) {
			if (!$this->tenkanCrossesBelowKijun($data, $idx)) {
				return false;
			}
			if ($this->kumoFilter && !$this->priceBelowCloud($data, $idx)) {
				return false;
			}
		} else {
			if (!$this->priceCrossesBelowCloud($data, $idx)) {
				return false;
			}
		}

		if ($this->chikouFilter && !$this->chikouConfirmsShort($data, $idx)) {
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
	// Ichimoku calculation
	// ------------------------------------------------------------------

	/**
	 * Calculate full Ichimoku data from market candles.
	 *
	 * @return array{tenkan: float[], kijun: float[], senkouA: float[], senkouB: float[], chikou: float[]}|null
	 */
	private function calculateIchimoku(): ?array {
		$candles = $this->market->getCandles();
		$minRequired = max($this->tenkanPeriod, $this->kijunPeriod, $this->senkouBPeriod) + $this->displacement;
		if (count($candles) < $minRequired) {
			Logger::getLogger()->debug("[ICHI] Not enough candles: have " . count($candles) . ", need $minRequired");
			return null;
		}

		$highPrices = array_map(fn($c) => $c->getHighPrice(), $candles);
		$lowPrices = array_map(fn($c) => $c->getLowPrice(), $candles);
		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);

		return Ichimoku::calculateFromPrices(
			$highPrices, $lowPrices, $closePrices,
			$this->tenkanPeriod, $this->kijunPeriod, $this->senkouBPeriod, $this->displacement,
		);
	}

	// ------------------------------------------------------------------
	// TK Cross detection
	// ------------------------------------------------------------------

	/**
	 * Detect Tenkan-sen crossing above Kijun-sen (bullish TK Cross).
	 */
	private function tenkanCrossesAboveKijun(array $data, int $idx): bool {
		$log = Logger::getLogger();
		if ($idx < 1) {
			return false;
		}

		$prevT = $data['tenkan'][$idx - 1];
		$currT = $data['tenkan'][$idx];
		$prevK = $data['kijun'][$idx - 1];
		$currK = $data['kijun'][$idx];

		if (is_nan($prevT) || is_nan($currT) || is_nan($prevK) || is_nan($currK)) {
			$log->debug("[ICHI-TK↑] NaN values, skipping");
			return false;
		}

		$cross = $prevT <= $prevK && $currT > $currK;

		$log->debug(sprintf(
			"[ICHI-TK↑] prevT=%.8f prevK=%.8f | currT=%.8f currK=%.8f | cross=%s",
			$prevT, $prevK, $currT, $currK,
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	/**
	 * Detect Tenkan-sen crossing below Kijun-sen (bearish TK Cross).
	 */
	private function tenkanCrossesBelowKijun(array $data, int $idx): bool {
		$log = Logger::getLogger();
		if ($idx < 1) {
			return false;
		}

		$prevT = $data['tenkan'][$idx - 1];
		$currT = $data['tenkan'][$idx];
		$prevK = $data['kijun'][$idx - 1];
		$currK = $data['kijun'][$idx];

		if (is_nan($prevT) || is_nan($currT) || is_nan($prevK) || is_nan($currK)) {
			$log->debug("[ICHI-TK↓] NaN values, skipping");
			return false;
		}

		$cross = $prevT >= $prevK && $currT < $currK;

		$log->debug(sprintf(
			"[ICHI-TK↓] prevT=%.8f prevK=%.8f | currT=%.8f currK=%.8f | cross=%s",
			$prevT, $prevK, $currT, $currK,
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	// ------------------------------------------------------------------
	// Kumo Breakout detection
	// ------------------------------------------------------------------

	/**
	 * Get the upper and lower edges of the cloud at a given bar index.
	 *
	 * @return array{upper: float, lower: float}|null Null if cloud data unavailable.
	 */
	private function getCloudEdges(array $data, int $idx): ?array {
		$spanA = $data['senkouA'][$idx] ?? NAN;
		$spanB = $data['senkouB'][$idx] ?? NAN;

		if (is_nan($spanA) || is_nan($spanB)) {
			return null;
		}

		return [
			'upper' => max($spanA, $spanB),
			'lower' => min($spanA, $spanB),
		];
	}

	/**
	 * Detect price crossing above the upper cloud edge (bullish breakout).
	 */
	private function priceCrossesAboveCloud(array $data, int $idx): bool {
		$log = Logger::getLogger();
		if ($idx < 1) {
			return false;
		}

		$candles = $this->market->getCandles();
		$prevClose = $candles[$idx - 1]->getClosePrice();
		$currClose = $candles[$idx]->getClosePrice();

		$prevEdges = $this->getCloudEdges($data, $idx - 1);
		$currEdges = $this->getCloudEdges($data, $idx);

		if ($prevEdges === null || $currEdges === null) {
			$log->debug("[ICHI-KUMO↑] Cloud data unavailable");
			return false;
		}

		$cross = $prevClose <= $prevEdges['upper'] && $currClose > $currEdges['upper'];

		$log->debug(sprintf(
			"[ICHI-KUMO↑] prevClose=%.8f prevUpper=%.8f | currClose=%.8f currUpper=%.8f | cross=%s",
			$prevClose, $prevEdges['upper'], $currClose, $currEdges['upper'],
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	/**
	 * Detect price crossing below the lower cloud edge (bearish breakout).
	 */
	private function priceCrossesBelowCloud(array $data, int $idx): bool {
		$log = Logger::getLogger();
		if ($idx < 1) {
			return false;
		}

		$candles = $this->market->getCandles();
		$prevClose = $candles[$idx - 1]->getClosePrice();
		$currClose = $candles[$idx]->getClosePrice();

		$prevEdges = $this->getCloudEdges($data, $idx - 1);
		$currEdges = $this->getCloudEdges($data, $idx);

		if ($prevEdges === null || $currEdges === null) {
			$log->debug("[ICHI-KUMO↓] Cloud data unavailable");
			return false;
		}

		$cross = $prevClose >= $prevEdges['lower'] && $currClose < $currEdges['lower'];

		$log->debug(sprintf(
			"[ICHI-KUMO↓] prevClose=%.8f prevLower=%.8f | currClose=%.8f currLower=%.8f | cross=%s",
			$prevClose, $prevEdges['lower'], $currClose, $currEdges['lower'],
			$cross ? 'YES — SIGNAL!' : 'no',
		));

		return $cross;
	}

	// ------------------------------------------------------------------
	// Kumo position filter (price above/below cloud)
	// ------------------------------------------------------------------

	/**
	 * Check if the current close is above the cloud (for long filter).
	 */
	private function priceAboveCloud(array $data, int $idx): bool {
		$log = Logger::getLogger();
		$edges = $this->getCloudEdges($data, $idx);
		if ($edges === null) {
			$log->debug("[ICHI-KUMO-F] Cloud data unavailable for filter");
			return false;
		}

		$close = $this->market->getCandles()[$idx]->getClosePrice();
		$above = $close > $edges['upper'];

		$log->debug(sprintf(
			"[ICHI-KUMO-F↑] close=%.8f cloudUpper=%.8f | %s",
			$close, $edges['upper'],
			$above ? 'PASS — above cloud' : 'FAIL — not above cloud',
		));

		return $above;
	}

	/**
	 * Check if the current close is below the cloud (for short filter).
	 */
	private function priceBelowCloud(array $data, int $idx): bool {
		$log = Logger::getLogger();
		$edges = $this->getCloudEdges($data, $idx);
		if ($edges === null) {
			$log->debug("[ICHI-KUMO-F] Cloud data unavailable for filter");
			return false;
		}

		$close = $this->market->getCandles()[$idx]->getClosePrice();
		$below = $close < $edges['lower'];

		$log->debug(sprintf(
			"[ICHI-KUMO-F↓] close=%.8f cloudLower=%.8f | %s",
			$close, $edges['lower'],
			$below ? 'PASS — below cloud' : 'FAIL — not below cloud',
		));

		return $below;
	}

	// ------------------------------------------------------------------
	// Chikou Span confirmation
	// ------------------------------------------------------------------

	/**
	 * Chikou confirms long: current close > close from displacement bars ago.
	 */
	private function chikouConfirmsLong(array $data, int $idx): bool {
		$log = Logger::getLogger();
		$refIdx = $idx - $this->displacement;
		if ($refIdx < 0) {
			$log->debug("[ICHI-CHI↑] Not enough bars for Chikou comparison");
			return false;
		}

		$currentClose = $data['chikou'][$idx];
		$pastClose = $data['chikou'][$refIdx];
		$confirms = $currentClose > $pastClose;

		$log->debug(sprintf(
			"[ICHI-CHI↑] currentClose=%.8f pastClose[-%d]=%.8f | %s",
			$currentClose, $this->displacement, $pastClose,
			$confirms ? 'PASS — Chikou confirms' : 'FAIL — Chikou rejects',
		));

		return $confirms;
	}

	/**
	 * Chikou confirms short: current close < close from displacement bars ago.
	 */
	private function chikouConfirmsShort(array $data, int $idx): bool {
		$log = Logger::getLogger();
		$refIdx = $idx - $this->displacement;
		if ($refIdx < 0) {
			$log->debug("[ICHI-CHI↓] Not enough bars for Chikou comparison");
			return false;
		}

		$currentClose = $data['chikou'][$idx];
		$pastClose = $data['chikou'][$refIdx];
		$confirms = $currentClose < $pastClose;

		$log->debug(sprintf(
			"[ICHI-CHI↓] currentClose=%.8f pastClose[-%d]=%.8f | %s",
			$currentClose, $this->displacement, $pastClose,
			$confirms ? 'PASS — Chikou confirms' : 'FAIL — Chikou rejects',
		));

		return $confirms;
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
			new SignalType(),
			new ReverseSignals(),
			new TenkanPeriod(),
			new KijunPeriod(),
			new SenkouBPeriod(),
			new Displacement(),
			new KumoFilter(),
			new ChikouFilter(),
			new CooldownCandles(),
		]);
	}
}
