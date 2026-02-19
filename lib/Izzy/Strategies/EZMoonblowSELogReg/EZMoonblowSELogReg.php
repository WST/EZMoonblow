<?php

namespace Izzy\Strategies\EZMoonblowSELogReg;

use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\IMarket;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\CooldownCandles;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\FilterType;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\HoldingPeriod;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\LearningRate;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\LookbackWindow;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\NormalizationLookback;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\SignalMode;
use Izzy\Strategies\EZMoonblowSELogReg\Parameters\TrainingIterations;
use Izzy\System\Logger;

/**
 * Single-entry strategy based on Logistic Regression.
 *
 * Ported from capissimo's Pine Script v4 indicator
 * "Machine Learning: Logistic Regression (v.3)".
 *
 * On each bar, a simplified single-weight logistic regression model is
 * trained on the relationship between close prices and a synthetic
 * (non-linearly transformed) dataset. The model's loss and prediction
 * are minimax-normalized into the price range, then used to generate
 * BUY/SELL signals.
 *
 * Two signal modes:
 *   - Price:     BUY when close > scaled_loss, SELL when close < scaled_loss.
 *   - Crossover: BUY on crossover(scaled_loss, scaled_prediction),
 *                SELL on crossunder(scaled_loss, scaled_prediction).
 *
 * Optional filters:
 *   - Volatility: ATR(1) > ATR(10) — current volatility exceeds average.
 *   - Volume:     RSI(volume, 14) > 49 — active trading volume.
 *   - Both:       both conditions must hold simultaneously.
 *
 * Inherits SL/TP, Breakeven Lock, Partial Close, and EMA trend filter
 * from AbstractSingleEntryStrategy.
 */
class EZMoonblowSELogReg extends AbstractSingleEntryStrategy
{
	public static function getDisplayName(): string {
		return 'Logistic Regression Single Entry';
	}

	private int $lookbackWindow;
	private int $normalizationLookback;
	private float $learningRate;
	private int $trainingIterations;
	private string $signalMode;
	private string $filterType;
	private int $holdingPeriod;
	private int $cooldownCandles;

	/** History of loss values across bars (for minimax normalization). */
	private array $lossHistory = [];

	/** History of prediction values across bars (for minimax normalization). */
	private array $predictionHistory = [];

	/** Previous bar's scaled loss (for crossover detection). */
	private ?float $prevScaledLoss = null;

	/** Previous bar's scaled prediction (for crossover detection). */
	private ?float $prevScaledPrediction = null;

	/** Last computed signal: 1 = BUY, -1 = SELL, 0 = HOLD. */
	private int $lastSignal = 0;

	/** Open time of the candle where signal was last computed (dedup guard). */
	private int $lastComputedBarTime = 0;

	/** Cached signal for the current bar. */
	private int $currentSignal = 0;

	/** Whether the signal changed on the current bar. */
	private bool $currentSignalChanged = false;

	/** Candle open-time of the last entry (cooldown + holding period tracker). */
	private int $lastEntryTime = 0;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->lookbackWindow = max(2, (int)($params['lookbackWindow'] ?? 5));
		$this->normalizationLookback = max(2, (int)($params['normalizationLookback'] ?? 50));
		$this->learningRate = (float)($params['learningRate'] ?? 0.0009);
		$this->trainingIterations = max(1, (int)($params['trainingIterations'] ?? 1000));
		$this->signalMode = $params['signalMode'] ?? SignalMode::PRICE;
		$this->filterType = $params['filterType'] ?? FilterType::NONE;
		$this->holdingPeriod = max(0, (int)($params['holdingPeriod'] ?? 5));
		$this->cooldownCandles = max(0, (int)($params['cooldownCandles'] ?? 0));
	}

	/**
	 * @inheritDoc
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
		$this->ensureSignalComputed();

		if (!$this->currentSignalChanged || $this->currentSignal !== 1) {
			return false;
		}
		if (!$this->cooldownElapsed()) {
			return false;
		}

		$this->markEntry();
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function detectShortSignal(): bool {
		$this->ensureSignalComputed();

		if (!$this->currentSignalChanged || $this->currentSignal !== -1) {
			return false;
		}
		if (!$this->cooldownElapsed()) {
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
	// Core ML signal computation
	// ------------------------------------------------------------------

	/**
	 * Compute and cache the ML signal for the current bar.
	 *
	 * Called from both detectLongSignal() and detectShortSignal()
	 * but only actually computes once per bar (dedup via candle time).
	 */
	private function ensureSignalComputed(): void {
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return;
		}

		$currentBarTime = (int)end($candles)->getOpenTime();
		if ($currentBarTime === $this->lastComputedBarTime) {
			return;
		}
		$this->lastComputedBarTime = $currentBarTime;

		$log = Logger::getLogger();
		$count = count($candles);

		// Need lookbackWindow + 2 prices to compute lookbackWindow log-returns
		// plus 1 lagged return for X.
		$minRequired = $this->lookbackWindow + 2;
		if ($count < $minRequired) {
			$log->debug("[LOGREG] Not enough candles: {$count} < {$minRequired}");
			$this->currentSignal = 0;
			$this->currentSignalChanged = false;
			return;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);

		// Compute log-returns for the model window + 1 extra for lag.
		$windowPrices = array_slice($closePrices, -($this->lookbackWindow + 2));
		$logReturns = LogisticRegression::synthesize($windowPrices);

		// X = lagged log-returns (predict current from previous).
		// Y = current log-returns.
		$X = array_slice($logReturns, 1, $this->lookbackWindow);
		$Y = array_slice($logReturns, 2, $this->lookbackWindow);

		// Train the model.
		$result = LogisticRegression::train(
			$X,
			$Y,
			$this->lookbackWindow,
			$this->learningRate,
			$this->trainingIterations,
		);

		$loss = $result['loss'];
		$prediction = $result['prediction'];

		$this->lossHistory[] = $loss;
		$this->predictionHistory[] = $prediction;

		$nlbk = $this->normalizationLookback;

		// Cap history to 2× normalization lookback to prevent unbounded memory growth.
		$maxHistory = $nlbk * 2;
		if (count($this->lossHistory) > $maxHistory) {
			$this->lossHistory = array_slice($this->lossHistory, -$maxHistory);
			$this->predictionHistory = array_slice($this->predictionHistory, -$maxHistory);
		}

		if (count($this->lossHistory) < $nlbk) {
			$log->debug(sprintf(
				"[LOGREG] Building history: %d/%d bars",
				count($this->lossHistory), $nlbk,
			));
			$this->currentSignal = 0;
			$this->currentSignalChanged = false;
			return;
		}

		// Minimax normalize loss and prediction into the price range.
		$priceSlice = array_slice($closePrices, -$nlbk);
		$priceMin = min($priceSlice);
		$priceMax = max($priceSlice);

		$scaledLoss = LogisticRegression::minimax(
			$loss, $this->lossHistory, $nlbk, $priceMin, $priceMax,
		);
		$scaledPrediction = LogisticRegression::minimax(
			$prediction, $this->predictionHistory, $nlbk, $priceMin, $priceMax,
		);

		$currentClose = end($closePrices);

		// Check optional filters.
		$filterPasses = $this->checkFilter($candles);

		$prevSignal = $this->lastSignal;

		if ($filterPasses) {
			if ($this->signalMode === SignalMode::PRICE) {
				if ($currentClose < $scaledLoss) {
					$this->lastSignal = -1;
				} elseif ($currentClose > $scaledLoss) {
					$this->lastSignal = 1;
				}
				// else: signal unchanged (HOLD-like)
			} else {
				// Crossover mode.
				if ($this->prevScaledLoss !== null && $this->prevScaledPrediction !== null) {
					$crossOver = $this->prevScaledLoss <= $this->prevScaledPrediction
						&& $scaledLoss > $scaledPrediction;
					$crossUnder = $this->prevScaledLoss >= $this->prevScaledPrediction
						&& $scaledLoss < $scaledPrediction;

					if ($crossUnder) {
						$this->lastSignal = -1;
					} elseif ($crossOver) {
						$this->lastSignal = 1;
					}
				}
			}
		}

		$this->prevScaledLoss = $scaledLoss;
		$this->prevScaledPrediction = $scaledPrediction;

		$this->currentSignal = $this->lastSignal;
		$this->currentSignalChanged = ($this->lastSignal !== $prevSignal && $this->lastSignal !== 0);

		$log->debug(sprintf(
			"[LOGREG] close=%.8f scaledLoss=%.6f scaledPred=%.6f signal=%d changed=%s filter=%s mode=%s",
			$currentClose, $scaledLoss, $scaledPrediction,
			$this->lastSignal,
			$this->currentSignalChanged ? 'YES' : 'no',
			$filterPasses ? 'pass' : 'fail',
			$this->signalMode,
		));
	}

	// ------------------------------------------------------------------
	// Filters (ported from Pine Script)
	// ------------------------------------------------------------------

	/**
	 * Check the configured filter condition.
	 *
	 * @param array $candles Market candles.
	 * @return bool True if filter passes (entry allowed).
	 */
	private function checkFilter(array $candles): bool {
		return match ($this->filterType) {
			FilterType::VOLATILITY => $this->volatilityFilter($candles),
			FilterType::VOLUME => $this->volumeFilter($candles),
			FilterType::BOTH => $this->volatilityFilter($candles) && $this->volumeFilter($candles),
			default => true,
		};
	}

	/**
	 * Volatility filter: ATR(1) > ATR(10).
	 *
	 * Current single-candle range exceeds the 10-candle average range,
	 * indicating elevated volatility suitable for entry.
	 */
	private function volatilityFilter(array $candles): bool {
		$highs = array_map(fn($c) => $c->getHighPrice(), $candles);
		$lows = array_map(fn($c) => $c->getLowPrice(), $candles);
		$closes = array_map(fn($c) => $c->getClosePrice(), $candles);

		$atr1 = LogisticRegression::atr($highs, $lows, $closes, 1);
		$atr10 = LogisticRegression::atr($highs, $lows, $closes, 10);

		if ($atr10 <= 0.0) {
			return false;
		}

		$pass = $atr1 > $atr10;

		Logger::getLogger()->debug(sprintf(
			"[LOGREG-VOL] ATR(1)=%.8f ATR(10)=%.8f | %s",
			$atr1, $atr10, $pass ? 'PASS' : 'FAIL',
		));

		return $pass;
	}

	/**
	 * Volume filter: RSI(volume, 14) > 49.
	 *
	 * Pine Script uses HMA(RSI(volume, 14), 10) > 49; we simplify
	 * to plain RSI since HMA is not available in the project.
	 */
	private function volumeFilter(array $candles): bool {
		$volumes = array_map(fn($c) => $c->getVolume(), $candles);

		if (count($volumes) < 15) {
			return false;
		}

		$rsiValues = RSI::calculateFromPrices($volumes, 14);
		if (empty($rsiValues)) {
			return false;
		}

		$latestRSI = end($rsiValues);
		$pass = $latestRSI > 49.0;

		Logger::getLogger()->debug(sprintf(
			"[LOGREG-VOL-RSI] RSI(volume,14)=%.2f | %s",
			$latestRSI, $pass ? 'PASS' : 'FAIL',
		));

		return $pass;
	}

	// ------------------------------------------------------------------
	// Cooldown / holding period logic
	// ------------------------------------------------------------------

	/**
	 * Check if cooldown (based on both cooldownCandles and holdingPeriod)
	 * has elapsed since the last entry.
	 */
	private function cooldownElapsed(): bool {
		if ($this->lastEntryTime === 0) {
			return true;
		}

		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return true;
		}

		$currentTime = (int)end($candles)->getOpenTime();
		$tfSeconds = $this->market->getPair()->getTimeframe()->toSeconds();

		$effectiveCooldown = max($this->cooldownCandles, $this->holdingPeriod);
		if ($effectiveCooldown <= 0) {
			return true;
		}

		$cooldownSeconds = $effectiveCooldown * $tfSeconds;
		return ($currentTime - $this->lastEntryTime) >= $cooldownSeconds;
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
			new LookbackWindow(),
			new NormalizationLookback(),
			new LearningRate(),
			new TrainingIterations(),
			new SignalMode(),
			new FilterType(),
			new HoldingPeriod(),
			new CooldownCandles(),
		]);
	}
}
