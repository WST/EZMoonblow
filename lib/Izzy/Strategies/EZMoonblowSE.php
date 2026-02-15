<?php

namespace Izzy\Strategies;

use Izzy\Enums\TimeFrameEnum;
use Izzy\Indicators\EMA;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IMarket;
use Izzy\System\Logger;

/**
 * Single-entry mean-reversion strategy within a trend context.
 *
 * The core idea: determine trend direction from daily price vs EMA,
 * then enter at the extreme of a small oscillation (dip in uptrend,
 * bounce in downtrend). This "buy the dip / sell the rally" approach
 * provides excellent entry prices where the trend itself acts as a
 * catalyst for favorable price movement.
 *
 * This approach works especially well with aggressive Breakeven Lock:
 * entering at oscillation extremes means price almost always moves
 * at least a small amount in the trend direction, triggering BL
 * before the stop-loss is reached.
 *
 * Long entry conditions:
 *   - Daily close > EMA(slow) on 1D (uptrend territory)
 *   - RSI on 1H crosses BELOW rsiLongThreshold (entering oversold =
 *     bottom of dip, catching the valley)
 *
 * Short entry conditions:
 *   - Daily close < EMA(slow) on 1D (downtrend territory)
 *   - RSI on 1H crosses ABOVE rsiShortThreshold (entering overbought =
 *     top of bounce, catching the peak)
 */
class EZMoonblowSE extends AbstractSingleEntryStrategy
{
	/** How many daily candles to request for EMA calculation. */
	private const int DAILY_CANDLES_COUNT = 250;

	/** Fast EMA period for the daily trend filter. */
	private int $emaFastPeriod;

	/** Slow EMA period for the daily trend filter. */
	private int $emaSlowPeriod;

	/** RSI threshold for long entries (RSI crosses below = dip detected). */
	private float $rsiLongThreshold;

	/** RSI threshold for short entries (RSI crosses above = bounce detected). */
	private float $rsiShortThreshold;

	/** Minimum candles to wait between entries (prevents whipsaw). */
	private int $cooldownCandles;

	/** Candle open-time of the last entry signal (cooldown tracker). */
	private int $lastEntryTime = 0;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->emaFastPeriod = (int)($params['emaFastPeriod'] ?? 20);
		$this->emaSlowPeriod = (int)($params['emaSlowPeriod'] ?? 50);
		$this->rsiLongThreshold = (float)($params['rsiLongThreshold'] ?? 40);
		$this->rsiShortThreshold = (float)($params['rsiShortThreshold'] ?? 60);
		$this->cooldownCandles = (int)($params['cooldownCandles'] ?? 0);
	}

	/**
	 * @inheritDoc
	 */
	public function useIndicators(): array {
		return [RSI::class];
	}

	/**
	 * Timeframes needed beyond the market's native timeframe.
	 * Used by the backtester to pre-load historical candles.
	 *
	 * @return TimeFrameEnum[]
	 */
	public static function requiredTimeframes(): array {
		return [TimeFrameEnum::TF_1DAY];
	}

	// ------------------------------------------------------------------
	// Entry signal detection
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public function shouldLong(): bool {
		$log = Logger::getLogger();

		if (!$this->cooldownElapsed()) {
			return false;
		}

		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			$log->debug("[LONG] No daily candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaSlow)) {
			$log->debug("[LONG] EMA array empty (slow=" . count($emaSlow) . ")");
			return false;
		}

		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		$log->debug(sprintf(
			"[LONG] close=%.8f | EMA(%d)=%.8f | close>ema=%s",
			$latestDailyClose,
			$this->emaSlowPeriod, $latestEmaSlow,
			$latestDailyClose > $latestEmaSlow ? 'YES' : 'NO',
		));

		// Trend filter: daily close above EMA(slow) = uptrend territory.
		if ($latestDailyClose <= $latestEmaSlow) {
			return false;
		}

		// Entry signal: RSI drops below threshold = entering oversold zone.
		// In an uptrend this means a temporary dip — enter long to catch the valley.
		if (!$this->rsiCrossesBelow($this->rsiLongThreshold)) {
			return false;
		}

		$this->markEntry();
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function shouldShort(): bool {
		$log = Logger::getLogger();

		if (!$this->cooldownElapsed()) {
			return false;
		}

		$dailyCandles = $this->getDailyCandles();
		if ($dailyCandles === null || empty($dailyCandles)) {
			$log->debug("[SHORT] No daily candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $dailyCandles);
		$emaSlow = EMA::calculateFromPrices($closePrices, $this->emaSlowPeriod);

		if (empty($emaSlow)) {
			$log->debug("[SHORT] EMA array empty (slow=" . count($emaSlow) . ")");
			return false;
		}

		$latestEmaSlow = end($emaSlow);
		$latestDailyClose = end($closePrices);

		$log->debug(sprintf(
			"[SHORT] close=%.8f | EMA(%d)=%.8f | close<ema=%s",
			$latestDailyClose,
			$this->emaSlowPeriod, $latestEmaSlow,
			$latestDailyClose < $latestEmaSlow ? 'YES' : 'NO',
		));

		// Trend filter: daily close below EMA(slow) = downtrend territory.
		if ($latestDailyClose >= $latestEmaSlow) {
			return false;
		}

		// Entry signal: RSI rises above threshold = entering overbought zone.
		// In a downtrend this means a temporary bounce — enter short to catch the peak.
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
	 * Prevents whipsaw: rapid re-entries after a stop-loss hit that
	 * often lead to cascading losses in choppy markets.
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
	// Multi-timeframe helpers
	// ------------------------------------------------------------------

	/**
	 * Request daily candles for EMA calculation.
	 * Uses lastCandle time instead of wall-clock time to avoid look-ahead bias in backtests.
	 *
	 * @return ICandle[]|null Array of daily candles or null if still loading.
	 */
	private function getDailyCandles(): ?array {
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return null;
		}
		$endTime = (int)end($candles)->getOpenTime();
		$startTime = $endTime - self::DAILY_CANDLES_COUNT * TimeFrameEnum::TF_1DAY->toSeconds();
		$candles = $this->market->requestCandles(TimeFrameEnum::TF_1DAY, $startTime, $endTime);
		$count = $candles !== null ? count($candles) : 'null';
		$need = self::DAILY_CANDLES_COUNT;
		Logger::getLogger()->debug("[EMA] getDailyCandles: need=$need, got=$count, range=" . date('Y-m-d', $startTime) . " → " . date('Y-m-d', $endTime));
		return $candles;
	}

	// ------------------------------------------------------------------
	// RSI crossover detection
	// ------------------------------------------------------------------

	/**
	 * Detect RSI crossing above a threshold (pullback recovery signal).
	 *
	 * In an uptrend, a pullback causes RSI to dip. When it crosses
	 * back above the threshold, the pullback is ending and the trend
	 * is likely to resume. This is a point-in-time event (fires once
	 * per crossing), avoiding the over-triggering of zone checks.
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
	 * Detect RSI crossing below a threshold (relief rally fading signal).
	 *
	 * In a downtrend, a relief rally pushes RSI up. When it crosses
	 * back below the threshold, the rally is fading and selling pressure
	 * is resuming.
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
	// Display
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public static function formatParameterName(string $paramName): string {
		$names = [
			'emaFastPeriod' => 'EMA fast period (1D)',
			'emaSlowPeriod' => 'EMA slow period (1D)',
			'rsiLongThreshold' => 'RSI oversold threshold for longs (1H)',
			'rsiShortThreshold' => 'RSI overbought threshold for shorts (1H)',
			'cooldownCandles' => 'Cooldown between entries (candles)',
		];
		return $names[$paramName] ?? parent::formatParameterName($paramName);
	}
}
