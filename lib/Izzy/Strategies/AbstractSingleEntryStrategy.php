<?php

namespace Izzy\Strategies;

use Izzy\Enums\EntryVolumeModeEnum;
use Izzy\Enums\MarginModeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;
use Izzy\System\Logger;
use Izzy\System\QueueTask;

/**
 * Base class for single-entry trading strategies.
 *
 * Unlike DCA strategies that use a grid of averaging orders,
 * single-entry strategies open one position with fixed size,
 * a stop-loss and a take-profit. They optionally support a
 * "Breakeven Lock" mechanism: partial close + move SL to entry.
 */
abstract class AbstractSingleEntryStrategy extends AbstractStrategy
{
	/** Parsed entry volume value. */
	protected float $entryVolume;

	/** Parsed entry volume mode. */
	protected EntryVolumeModeEnum $volumeMode;

	/** Stop-loss distance from entry (percentage, positive number). */
	protected float $stopLossPercent;

	/** Take-profit distance from entry (percentage, positive number). */
	protected float $takeProfitPercent;

	/** Whether the strategy expects isolated margin mode on the exchange. */
	protected bool $useIsolatedMargin;

	/** Whether Breakeven Lock is enabled. */
	protected bool $breakevenLockEnabled;

	/** What portion of the position to close during Breakeven Lock (0–100%). */
	protected float $breakevenLockClosePercent;

	/** At what % of the way to TP the Breakeven Lock should trigger (10–90%). */
	protected float $breakevenLockTriggerPercent;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->initializeSettings();
	}

	/**
	 * Parse strategy parameters.
	 */
	private function initializeSettings(): void {
		// Parse entry volume (supports: "140", "5%", "5%M", "0.002 BTC").
		$parsed = EntryVolumeParser::parse($this->params['entryVolume'] ?? '100');
		$this->entryVolume = $parsed->getValue();
		$this->volumeMode = $parsed->getMode();

		$this->stopLossPercent = (float)str_replace('%', '', $this->params['stopLossPercent'] ?? '2');
		$this->takeProfitPercent = (float)str_replace('%', '', $this->params['takeProfitPercent'] ?? '3');
		$this->useIsolatedMargin = filter_var($this->params['useIsolatedMargin'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$this->breakevenLockEnabled = filter_var($this->params['breakevenLockEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$this->breakevenLockClosePercent = (float)($this->params['breakevenLockClosePercent'] ?? 50);
		$this->breakevenLockTriggerPercent = (float)($this->params['breakevenLockTriggerPercent'] ?? 50);
	}

	/**
	 * Resolve entry volume in quote currency using the current trading context.
	 *
	 * @return float Volume in quote currency (e.g. USDT).
	 */
	protected function resolveEntryVolume(): float {
		$context = $this->market->getTradingContext();
		return match ($this->volumeMode) {
			EntryVolumeModeEnum::ABSOLUTE_QUOTE => $this->entryVolume,
			EntryVolumeModeEnum::PERCENT_BALANCE => $context->getBalance() * ($this->entryVolume / 100),
			EntryVolumeModeEnum::PERCENT_MARGIN => $context->getMargin() * ($this->entryVolume / 100),
			EntryVolumeModeEnum::ABSOLUTE_BASE => $this->entryVolume * $context->getCurrentPrice()->getAmount(),
		};
	}

	// ------------------------------------------------------------------
	// Entry handlers
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public function handleLong(IMarket $market): IStoredPosition|false {
		return $this->executeEntry($market, PositionDirectionEnum::LONG);
	}

	/**
	 * @inheritDoc
	 */
	public function handleShort(IMarket $market): IStoredPosition|false {
		return $this->executeEntry($market, PositionDirectionEnum::SHORT);
	}

	/**
	 * Common entry logic for both directions.
	 */
	private function executeEntry(IMarket $market, PositionDirectionEnum $direction): IStoredPosition|false {
		$volumeQuote = Money::from($this->resolveEntryVolume());
		$currentPrice = $market->getCurrentPrice();
		$volumeBase = $market->calculateQuantity($volumeQuote, $currentPrice);

		// Open the position at market price.
		$position = $market->openPosition($volumeBase, $direction, $this->takeProfitPercent);
		if (!$position) {
			return false;
		}

		// Calculate and set SL price.
		$slPrice = $currentPrice->modifyByPercentWithDirection(-$this->stopLossPercent, $direction);
		$market->setStopLoss($slPrice);
		$position->setStopLossPrice($slPrice);
		$position->setExpectedStopLossPercent($this->stopLossPercent);

		// Calculate and set TP price.
		$tpPrice = $currentPrice->modifyByPercentWithDirection($this->takeProfitPercent, $direction);
		$position->setTakeProfitPrice($tpPrice);
		$position->setExpectedProfitPercent($this->takeProfitPercent);

		$position->save();
		return $position;
	}

	// ------------------------------------------------------------------
	// Position update (Breakeven Lock)
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	public function updatePosition(IStoredPosition $position): void {
		// Update the TP order on the exchange if the average entry changed.
		$position->updateTakeProfit($this->market);

		// Breakeven Lock: if enabled, not yet executed, and position is in profit.
		if ($this->breakevenLockEnabled && !$this->isBreakevenLockExecuted($position)) {
			$tpPrice = $position->getTakeProfitPrice();
			if ($tpPrice === null) {
				return;
			}

			// Check if current price has progressed enough towards TP.
			$entryPrice = $position->getAverageEntryPrice();
			$currentPrice = $position->getCurrentPrice();
			$direction = $position->getDirection();

			$progressPercent = $this->calculateProgressToTP($entryPrice, $currentPrice, $tpPrice, $direction);
			if ($progressPercent >= $this->breakevenLockTriggerPercent) {
				$this->executeBreakevenLock($position);
			}
		}
	}

	/**
	 * Execute Breakeven Lock: partial close + move SL to entry.
	 */
	protected function executeBreakevenLock(IStoredPosition $position): void {
		$logger = Logger::getLogger();
		$market = $this->market;

		// 1. Partially close the position.
		$currentVolume = $position->getVolume();
		$closeAmount = $currentVolume->getAmount() * ($this->breakevenLockClosePercent / 100);
		$closeVolume = Money::from($closeAmount, $currentVolume->getCurrency());

		// Compute the exact trigger price (breakevenLockTriggerPercent% of the way
		// from entry to TP). In backtesting, the simulated tick can overshoot this
		// level significantly; using the exact trigger price keeps PnL realistic,
		// similar to how TP/SL hits use the order price rather than the tick price.
		$entryPrice = $position->getAverageEntryPrice();
		$tpPrice = $position->getTakeProfitPrice();
		$closePrice = null;
		if ($entryPrice !== null && $tpPrice !== null) {
			$triggerAmount = $entryPrice->getAmount()
				+ ($tpPrice->getAmount() - $entryPrice->getAmount())
				* ($this->breakevenLockTriggerPercent / 100);
			$closePrice = Money::from($triggerAmount, $entryPrice->getCurrency());
		}

		if (!$market->partialClose($closeVolume, isBreakevenLock: true, closePrice: $closePrice)) {
			$logger->error("Breakeven Lock: failed to partially close position on {$market->getTicker()}");
			return;
		}

		// Sync the in-memory position volume with the reduced value saved by
		// partialClose(). Without this, the subsequent $position->save() would
		// overwrite the DB with the stale (pre-close) volume.
		$newVolume = Money::from($currentVolume->getAmount() - $closeAmount, $currentVolume->getCurrency());
		$position->setVolume($newVolume);

		// 2. Move SL to just below entry (for LONG) or just above entry (for SHORT).
		// Bybit requires SL to be on the loss side — it cannot be at or above entry
		// for LONG, or at or below entry for SHORT. We nudge it by 1 tick into the
		// loss zone. This is still effectively breakeven because the partial close
		// above already locked in profit that covers this minimal slippage.
		$entryPrice = $position->getAverageEntryPrice();
		$tickSize = (float)$market->getExchange()->getTickSize($market);
		$direction = $position->getDirection();
		$offset = $direction->isLong() ? -$tickSize : $tickSize;
		$newSLPrice = Money::from($entryPrice->getAmount() + $offset, $entryPrice->getCurrency());

		if (!$market->setStopLoss($newSLPrice)) {
			$logger->error("Breakeven Lock: failed to move SL to entry on {$market->getTicker()}");
			return;
		}

		$position->setStopLossPrice($newSLPrice);
		$this->markBreakevenLockExecuted($position);
		$position->save();

		// Calculate the profit locked by the partial close.
		$lockedProfit = 0.0;
		if ($closePrice !== null && $entryPrice !== null) {
			$priceDiff = $direction->isLong()
				? ($closePrice->getAmount() - $entryPrice->getAmount())
				: ($entryPrice->getAmount() - $closePrice->getAmount());
			$lockedProfit = $priceDiff * $closeAmount;
		}

		$logger->info("Breakeven Lock executed on {$market->getTicker()}: closed {$closeVolume->format()}, SL moved to {$newSLPrice->format()}");

		// Send Telegram notification about Breakeven Lock (skip in backtests).
		if (!$logger->isBacktestMode()) {
			QueueTask::addTelegramNotification_breakevenLock(
				$market,
				$position,
				$closeAmount,
				$lockedProfit,
			);
		}
	}

	/**
	 * Calculate how far current price has progressed towards TP (0–100+).
	 */
	private function calculateProgressToTP(Money $entry, Money $current, Money $tp, PositionDirectionEnum $direction): float {
		$entryAmount = $entry->getAmount();
		$currentAmount = $current->getAmount();
		$tpAmount = $tp->getAmount();

		$totalDistance = $tpAmount - $entryAmount;
		if (abs($totalDistance) < 1e-12) {
			return 0.0;
		}
		$currentProgress = $currentAmount - $entryAmount;
		return ($currentProgress / $totalDistance) * 100;
	}

	/**
	 * Check if Breakeven Lock has already been executed for this position.
	 *
	 * After execution, SL is placed 1 tick from entry.  We detect this by
	 * checking whether the distance between SL and entry is within 2 ticks.
	 * This is more reliable than a fixed-percentage threshold because the
	 * tick size varies by instrument (e.g. 0.01 for XRP, 0.10 for BTC).
	 */
	private function isBreakevenLockExecuted(IStoredPosition $position): bool {
		$slPrice = $position->getStopLossPrice();
		if ($slPrice === null) {
			return false;
		}
		$entryPrice = $position->getAverageEntryPrice();
		$tickSize = (float)$this->market->getExchange()->getTickSize($this->market);
		$diff = abs($slPrice->getAmount() - $entryPrice->getAmount());

		// After Breakeven Lock, SL is exactly 1 tick from entry.
		// Allow up to 2 ticks as tolerance.
		return $diff <= $tickSize * 2;
	}

	/**
	 * Mark Breakeven Lock as executed by updating SL in the stored position.
	 * The lock status is determined by isBreakevenLockExecuted() based on SL vs entry price,
	 * so no additional flag is needed.
	 */
	private function markBreakevenLockExecuted(IStoredPosition $position): void {
		// The lock is detected by isBreakevenLockExecuted() via SL >= entry (long) or SL <= entry (short).
		// No additional flag needed — the SL price update itself is the marker.
	}

	// ------------------------------------------------------------------
	// Exchange settings validation
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 *
	 * Single-entry strategies check:
	 * - Isolated margin mode (if configured).
	 * - Leverage vs stop-loss distance: 100/leverage must be > stopLossPercent.
	 */
	public function validateExchangeSettings(IMarket $market): StrategyValidationResult {
		$result = parent::validateExchangeSettings($market);

		// Validate Breakeven Lock trigger threshold (exchange-independent).
		if ($this->breakevenLockEnabled) {
			if ($this->breakevenLockTriggerPercent < 10 || $this->breakevenLockTriggerPercent > 90) {
				$result->addError(
					"Breakeven Lock trigger ({$this->breakevenLockTriggerPercent}%) must be between 10% and 90%. "
					. 'Values outside this range are either too risky or impractical.'
				);
			}
		}

		if (!$market->getMarketType()->isFutures()) {
			return $result;
		}

		$exchange = $market->getExchange();

		// Check isolated margin mode; attempt to switch automatically if needed.
		if ($this->useIsolatedMargin) {
			$marginMode = $exchange->getMarginMode($market);
			if ($marginMode === null) {
				$result->addWarning(
					'Could not verify margin mode on the exchange (API error). '
					. 'Strategy expects Isolated margin mode.'
				);
			} elseif (!$marginMode->isIsolated()) {
				// Attempt to switch to Isolated automatically.
				$switched = $exchange->switchMarginMode($market, MarginModeEnum::ISOLATED);
				if (!$switched) {
					$result->addError(
						'Strategy requires Isolated margin mode, '
						. "but the exchange is configured as '{$marginMode->getLabel()}'. "
						. 'Automatic switch failed. Please switch to Isolated margin in exchange settings.'
					);
				}
			}
		}

		// Check leverage vs SL distance.
		$leverage = $exchange->getLeverage($market);
		if ($leverage === null) {
			$result->addWarning(
				'Could not verify leverage on the exchange (API error). '
				. 'Cannot check if stop-loss distance is safe for the current leverage.'
			);
		} elseif ($leverage > 0) {
			$maxLossBeforeLiquidation = 100.0 / $leverage;
			if ($this->stopLossPercent >= $maxLossBeforeLiquidation) {
				$result->addError(
					"Stop-loss distance ({$this->stopLossPercent}%) exceeds maximum allowed by leverage ({$leverage}x). "
					. "At {$leverage}x leverage, liquidation occurs at ~"
					. number_format($maxLossBeforeLiquidation, 2) . '% loss. '
					. 'Please reduce the stop-loss distance or decrease leverage.'
				);
			} elseif ($this->stopLossPercent > $maxLossBeforeLiquidation * 0.8) {
				$result->addWarning(
					"Stop-loss distance ({$this->stopLossPercent}%) is close to the liquidation threshold "
					. '(' . number_format($maxLossBeforeLiquidation, 2) . "% at {$leverage}x leverage). "
					. 'Consider reducing leverage for safer operation.'
				);
			}
		}

		return $result;
	}

	// ------------------------------------------------------------------
	// Display methods (mirroring AbstractDCAStrategy)
	// ------------------------------------------------------------------

	/**
	 * Convert machine-readable parameter names to human-readable format.
	 */
	public static function formatParameterName(string $paramName): string {
		$names = [
			'entryVolume' => 'Entry volume (USDT, %, %M, or base currency)',
			'stopLossPercent' => 'Stop-loss distance (%)',
			'takeProfitPercent' => 'Take-profit distance (%)',
			'useIsolatedMargin' => 'Use isolated margin',
			'breakevenLockEnabled' => 'Breakeven Lock enabled',
			'breakevenLockTriggerPercent' => 'Breakeven Lock trigger (% of way to TP)',
			'breakevenLockClosePercent' => 'Breakeven Lock close portion (%)',
		];
		return $names[$paramName] ?? $paramName;
	}

	/**
	 * Format parameter value for human-readable display.
	 */
	public static function formatParameterValue(string $paramName, string $value): string {
		$booleanParams = ['useIsolatedMargin', 'breakevenLockEnabled'];
		if (in_array($paramName, $booleanParams)) {
			return match (strtolower($value)) {
				'yes', '1', 'true' => 'Yes',
				'no', '0', 'false', '' => 'No',
				default => $value,
			};
		}
		return $value;
	}

	/**
	 * Get strategy parameters filtered for display.
	 * Hides breakeven-related params if the feature is disabled.
	 *
	 * @return array Filtered parameters.
	 */
	public function getDisplayParameters(): array {
		$excluded = [];
		if (!$this->breakevenLockEnabled) {
			$excluded[] = 'breakevenLockTriggerPercent';
			$excluded[] = 'breakevenLockClosePercent';
		}
		return array_diff_key($this->params, array_flip($excluded));
	}

	// ------------------------------------------------------------------
	// Abstract methods for concrete strategies
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 */
	abstract public function shouldLong(): bool;

	/**
	 * @inheritDoc
	 */
	abstract public function shouldShort(): bool;

	/**
	 * Whether this strategy opens long positions.
	 */
	abstract public function doesLong(): bool;

	/**
	 * Whether this strategy opens short positions.
	 */
	abstract public function doesShort(): bool;
}
