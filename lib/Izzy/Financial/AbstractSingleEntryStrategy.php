<?php

namespace Izzy\Financial;

use Izzy\Enums\EntryVolumeModeEnum;
use Izzy\Enums\MarginModeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Parameters\BreakevenLockClosePercent;
use Izzy\Financial\Parameters\BreakevenLockEnabled;
use Izzy\Financial\Parameters\BreakevenLockTriggerPercent;
use Izzy\Financial\Parameters\BreakevenLockUseLimitOrder;
use Izzy\Financial\Parameters\EMAFilterPeriod;
use Izzy\Financial\Parameters\EMATrendFilter;
use Izzy\Financial\Parameters\EMATrendFilterTimeframe;
use Izzy\Financial\Parameters\EntryVolume;
use Izzy\Financial\Parameters\PartialCloseEnabled;
use Izzy\Financial\Parameters\PartialClosePercent;
use Izzy\Financial\Parameters\PartialCloseTriggerPercent;
use Izzy\Financial\Parameters\PartialCloseUseLimitOrder;
use Izzy\Financial\Parameters\StopLossCooldownMinutes;
use Izzy\Financial\Parameters\StopLossPercent;
use Izzy\Financial\Parameters\TakeProfitPercent;
use Izzy\Financial\Parameters\UseIsolatedMargin;
use Izzy\Indicators\EMA;
use Izzy\Interfaces\ICandle;
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

	/** Whether Partial Close is enabled. */
	protected bool $partialCloseEnabled;

	/** What portion of the position to close during Partial Close (0–100%). */
	protected float $partialClosePercent;

	/** At what % of the way to TP the Partial Close should trigger. */
	protected float $partialCloseTriggerPercent;

	/** Whether to use a limit order (instead of market) for Partial Close. */
	protected bool $partialCloseUseLimitOrder;

	/** Whether to use a limit order (instead of market) for Breakeven Lock. */
	protected bool $breakevenLockUseLimitOrder;

	/** Whether the EMA trend filter is enabled. */
	protected bool $emaTrendFilter;

	/** Timeframe for the EMA trend filter ('1d' or '1h'). */
	protected string $emaTrendFilterTimeframe;

	/** EMA period for the trend filter. */
	protected int $emaFilterPeriod;

	/** Minutes to wait before opening a new position after a stop-loss hit. */
	protected int $stopLossCooldownMinutes;

	/** Timestamp of the most recent stop-loss hit (used for cooldown). */
	protected int $lastStopLossTime = 0;

	/** How many candles of the filter timeframe to request. */
	private const int EMA_FILTER_CANDLES_COUNT = 250;

	/** Tracks which position (by createdAt) had its Partial Close executed. */
	private ?int $partialCloseForPosition = null;

	/** Exchange order ID for a pending Partial Close limit order. */
	private ?string $pendingPartialCloseOrderId = null;

	/** Exchange order ID for a pending Breakeven Lock limit order. */
	private ?string $pendingBreakevenLockOrderId = null;

	/** createdAt of the position that pending orders belong to. */
	private ?int $pendingOrdersForPosition = null;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->initializeSettings();
	}

	/**
	 * Parse strategy parameters from typed param objects.
	 */
	private function initializeSettings(): void {
		$parsed = EntryVolumeParser::parse($this->params[EntryVolume::getName()]->getRawValue());
		$this->entryVolume = $parsed->getValue();
		$this->volumeMode = $parsed->getMode();

		$this->stopLossPercent = $this->params[StopLossPercent::getName()]->getValue();
		$this->takeProfitPercent = $this->params[TakeProfitPercent::getName()]->getValue();
		$this->useIsolatedMargin = $this->params[UseIsolatedMargin::getName()]->getValue();
		$this->breakevenLockEnabled = $this->params[BreakevenLockEnabled::getName()]->getValue();
		$this->breakevenLockClosePercent = $this->params[BreakevenLockClosePercent::getName()]->getValue();
		$this->breakevenLockTriggerPercent = $this->params[BreakevenLockTriggerPercent::getName()]->getValue();
		$this->partialCloseEnabled = $this->params[PartialCloseEnabled::getName()]->getValue();
		$this->partialClosePercent = $this->params[PartialClosePercent::getName()]->getValue();
		$this->partialCloseTriggerPercent = $this->params[PartialCloseTriggerPercent::getName()]->getValue();
		$this->partialCloseUseLimitOrder = $this->params[PartialCloseUseLimitOrder::getName()]->getValue();
		$this->breakevenLockUseLimitOrder = $this->params[BreakevenLockUseLimitOrder::getName()]->getValue();
		$this->emaTrendFilter = $this->params[EMATrendFilter::getName()]->getValue();
		$this->emaTrendFilterTimeframe = $this->params[EMATrendFilterTimeframe::getName()]->getValue();
		$this->emaFilterPeriod = $this->params[EMAFilterPeriod::getName()]->getValue();
		$this->stopLossCooldownMinutes = $this->params[StopLossCooldownMinutes::getName()]->getValue();
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
		// Pass volume in quote currency (USDT). Market::openPosition and the
		// exchange driver convert to base currency internally. Do NOT
		// pre-convert here, otherwise the amount will be divided by price twice.
		$volumeQuote = Money::from($this->resolveEntryVolume());
		$currentPrice = $market->getCurrentPrice();

		// Open the position at market price.
		$position = $market->openPosition($volumeQuote, $direction, $this->takeProfitPercent);
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
		if (
			$position->getStatus()->isFinished()
			&& method_exists($position, 'getFinishReason')
			&& $position->getFinishReason()?->isStopLoss()
		) {
			$this->lastStopLossTime = $position->getFinishedAt() ?: time();
		}

		// Update the TP order on the exchange if the average entry changed.
		$position->updateTakeProfit($this->market);

		$tpPrice = $position->getTakeProfitPrice();
		if ($tpPrice === null) {
			return;
		}

		// Reset pending order state when position changes (new trade opened).
		$this->resetPendingOrdersIfNeeded($position);

		$entryPrice = $position->getAverageEntryPrice();
		$currentPrice = $position->getCurrentPrice();
		$direction = $position->getDirection();
		$progressPercent = $this->calculateProgressToTP($entryPrice, $currentPrice, $tpPrice, $direction);

		// In backtests, limit orders execute identically to market orders
		// (no commission simulation), so always use the immediate market path.
		// The limit order settings are still visible in the UI for XML generation.
		$isBacktest = Logger::getLogger()->isBacktestMode();

		// ----- Partial Close -----
		if ($this->partialCloseEnabled && !$this->isPartialCloseExecuted($position)) {
			if (!$isBacktest && $this->pendingPartialCloseOrderId !== null) {
				// A limit order was placed — check if it has been filled.
				$this->detectPendingPartialCloseFill($position);
			} elseif ($progressPercent >= $this->partialCloseTriggerPercent) {
				if ($this->partialCloseUseLimitOrder && !$isBacktest) {
					$this->placePartialCloseLimitOrder($position);
				} else {
					$this->executePartialClose($position);
				}
			}
		}

		// ----- Breakeven Lock -----
		if ($this->breakevenLockEnabled && !$this->isBreakevenLockExecuted($position)) {
			if (!$isBacktest && $this->pendingBreakevenLockOrderId !== null) {
				// A limit order was placed — check if it has been filled.
				$this->detectPendingBreakevenLockFill($position);
			} elseif ($progressPercent >= $this->breakevenLockTriggerPercent) {
				if ($this->breakevenLockUseLimitOrder && !$isBacktest) {
					$this->placeBreakevenLockLimitOrder($position);
				} else {
					$this->executeBreakevenLock($position);
				}
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

	// ------------------------------------------------------------------
	// Limit order placement and fill detection
	// ------------------------------------------------------------------

	/**
	 * Reset pending order tracking when the position changes.
	 */
	private function resetPendingOrdersIfNeeded(IStoredPosition $position): void {
		$createdAt = $position->getCreatedAt();
		if ($this->pendingOrdersForPosition !== $createdAt) {
			$this->pendingPartialCloseOrderId = null;
			$this->pendingBreakevenLockOrderId = null;
			$this->pendingOrdersForPosition = $createdAt;
		}
	}

	/**
	 * Compute the trigger price for a given % of the way from entry to TP.
	 */
	private function computeTriggerPrice(IStoredPosition $position, float $triggerPercent): ?Money {
		$entryPrice = $position->getAverageEntryPrice();
		$tpPrice = $position->getTakeProfitPrice();
		if ($entryPrice === null || $tpPrice === null) {
			return null;
		}
		$triggerAmount = $entryPrice->getAmount()
			+ ($tpPrice->getAmount() - $entryPrice->getAmount())
			* ($triggerPercent / 100);
		return Money::from($triggerAmount, $entryPrice->getCurrency());
	}

	/**
	 * Place a limit order for Partial Close.
	 */
	private function placePartialCloseLimitOrder(IStoredPosition $position): void {
		$logger = Logger::getLogger();
		$market = $this->market;
		$exchange = $market->getExchange();

		$currentVolume = $position->getVolume();
		$closeAmount = $currentVolume->getAmount() * ($this->partialClosePercent / 100);
		$closeVolume = Money::from($closeAmount, $currentVolume->getCurrency());

		$triggerPrice = $this->computeTriggerPrice($position, $this->partialCloseTriggerPercent);
		if ($triggerPrice === null) {
			return;
		}

		$orderId = $exchange->placeLimitClose(
			$market,
			$closeVolume,
			$triggerPrice,
			$position->getDirection(),
		);

		if ($orderId === false) {
			$logger->error("Partial Close: failed to place limit close on {$market->getTicker()}");
			return;
		}

		$this->pendingPartialCloseOrderId = $orderId;
		$logger->info("Partial Close: placed limit close on {$market->getTicker()}: {$closeVolume->format()} @ {$triggerPrice->format()} (order {$orderId})");
	}

	/**
	 * Detect if the pending Partial Close limit order has been filled.
	 */
	private function detectPendingPartialCloseFill(IStoredPosition $position): void {
		$logger = Logger::getLogger();
		$market = $this->market;
		$exchange = $market->getExchange();

		if ($exchange->hasActiveOrder($market, $this->pendingPartialCloseOrderId)) {
			return; // Still pending.
		}

		// Order is no longer active — it has been filled.
		$logger->info("Partial Close: limit order {$this->pendingPartialCloseOrderId} filled on {$market->getTicker()}");
		$this->pendingPartialCloseOrderId = null;

		// Sync volume from exchange: re-read position to get the post-fill volume.
		$freshPosition = $market->getExchange()->getCurrentFuturesPosition($market);
		if ($freshPosition !== null) {
			$position->setVolume($freshPosition->getVolume());
			$position->save();
		}

		$this->partialCloseForPosition = $position->getCreatedAt();
	}

	/**
	 * Place a limit order for Breakeven Lock.
	 */
	private function placeBreakevenLockLimitOrder(IStoredPosition $position): void {
		$logger = Logger::getLogger();
		$market = $this->market;
		$exchange = $market->getExchange();

		$currentVolume = $position->getVolume();
		$closeAmount = $currentVolume->getAmount() * ($this->breakevenLockClosePercent / 100);
		$closeVolume = Money::from($closeAmount, $currentVolume->getCurrency());

		$triggerPrice = $this->computeTriggerPrice($position, $this->breakevenLockTriggerPercent);
		if ($triggerPrice === null) {
			return;
		}

		$orderId = $exchange->placeLimitClose(
			$market,
			$closeVolume,
			$triggerPrice,
			$position->getDirection(),
		);

		if ($orderId === false) {
			$logger->error("Breakeven Lock: failed to place limit close on {$market->getTicker()}");
			return;
		}

		$this->pendingBreakevenLockOrderId = $orderId;
		$logger->info("Breakeven Lock: placed limit close on {$market->getTicker()}: {$closeVolume->format()} @ {$triggerPrice->format()} (order {$orderId})");
	}

	/**
	 * Detect if the pending Breakeven Lock limit order has been filled.
	 * On fill: sync volume from exchange + move SL to entry.
	 */
	private function detectPendingBreakevenLockFill(IStoredPosition $position): void {
		$logger = Logger::getLogger();
		$market = $this->market;
		$exchange = $market->getExchange();

		if ($exchange->hasActiveOrder($market, $this->pendingBreakevenLockOrderId)) {
			return; // Still pending.
		}

		// Order is no longer active — it has been filled.
		$logger->info("Breakeven Lock: limit order {$this->pendingBreakevenLockOrderId} filled on {$market->getTicker()}");
		$this->pendingBreakevenLockOrderId = null;

		// Sync volume from exchange.
		$freshPosition = $market->getExchange()->getCurrentFuturesPosition($market);
		if ($freshPosition !== null) {
			$position->setVolume($freshPosition->getVolume());
		}

		// Move SL to entry (1 tick offset into the loss zone).
		$entryPrice = $position->getAverageEntryPrice();
		$tickSize = (float)$market->getExchange()->getTickSize($market);
		$direction = $position->getDirection();
		$offset = $direction->isLong() ? -$tickSize : $tickSize;
		$newSLPrice = Money::from($entryPrice->getAmount() + $offset, $entryPrice->getCurrency());

		if (!$market->setStopLoss($newSLPrice)) {
			$logger->error("Breakeven Lock: failed to move SL to entry on {$market->getTicker()} after limit fill");
			return;
		}

		$position->setStopLossPrice($newSLPrice);
		$this->markBreakevenLockExecuted($position);
		$position->save();

		$logger->info("Breakeven Lock executed (limit) on {$market->getTicker()}: SL moved to {$newSLPrice->format()}");

		// Send Telegram notification (skip in backtests).
		if (!$logger->isBacktestMode()) {
			$closeAmount = $position->getVolume()->getAmount() * ($this->breakevenLockClosePercent / 100);
			$triggerPrice = $this->computeTriggerPrice($position, $this->breakevenLockTriggerPercent);
			$lockedProfit = 0.0;
			if ($triggerPrice !== null && $entryPrice !== null) {
				$priceDiff = $direction->isLong()
					? ($triggerPrice->getAmount() - $entryPrice->getAmount())
					: ($entryPrice->getAmount() - $triggerPrice->getAmount());
				$lockedProfit = $priceDiff * $closeAmount;
			}
			QueueTask::addTelegramNotification_breakevenLock(
				$market,
				$position,
				$closeAmount,
				$lockedProfit,
			);
		}
	}

	// ------------------------------------------------------------------
	// Partial Close
	// ------------------------------------------------------------------

	/**
	 * Execute Partial Close: close a portion of the position without moving SL.
	 */
	protected function executePartialClose(IStoredPosition $position): void {
		$logger = Logger::getLogger();
		$market = $this->market;

		$currentVolume = $position->getVolume();
		$closeAmount = $currentVolume->getAmount() * ($this->partialClosePercent / 100);
		$closeVolume = Money::from($closeAmount, $currentVolume->getCurrency());

		// Compute the exact trigger price for realistic PnL in backtests.
		$entryPrice = $position->getAverageEntryPrice();
		$tpPrice = $position->getTakeProfitPrice();
		$closePrice = null;
		if ($entryPrice !== null && $tpPrice !== null) {
			$triggerAmount = $entryPrice->getAmount()
				+ ($tpPrice->getAmount() - $entryPrice->getAmount())
				* ($this->partialCloseTriggerPercent / 100);
			$closePrice = Money::from($triggerAmount, $entryPrice->getCurrency());
		}

		if (!$market->partialClose($closeVolume, isBreakevenLock: false, closePrice: $closePrice)) {
			$logger->error("Partial Close: failed to partially close position on {$market->getTicker()}");
			return;
		}

		// Sync the in-memory position volume.
		$newVolume = Money::from($currentVolume->getAmount() - $closeAmount, $currentVolume->getCurrency());
		$position->setVolume($newVolume);

		$this->partialCloseForPosition = $position->getCreatedAt();
		$position->save();

		$lockedProfit = 0.0;
		if ($closePrice !== null && $entryPrice !== null) {
			$priceDiff = $position->getDirection()->isLong()
				? ($closePrice->getAmount() - $entryPrice->getAmount())
				: ($entryPrice->getAmount() - $closePrice->getAmount());
			$lockedProfit = $priceDiff * $closeAmount;
		}

		$logger->info("Partial Close executed on {$market->getTicker()}: closed {$closeVolume->format()}, profit " . number_format($lockedProfit, 2) . " USDT");

		// TODO: Send Telegram notification about Partial Close (skip in backtests).
	}

	/**
	 * Check if Partial Close has already been executed for this position.
	 * Tracks by position createdAt timestamp; automatically resets for new positions.
	 */
	private function isPartialCloseExecuted(IStoredPosition $position): bool {
		return $this->partialCloseForPosition === $position->getCreatedAt();
	}

	// ------------------------------------------------------------------
	// Progress calculation
	// ------------------------------------------------------------------

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

		// Validate Partial Close trigger threshold (exchange-independent).
		if ($this->partialCloseEnabled) {
			if ($this->partialCloseTriggerPercent < 10 || $this->partialCloseTriggerPercent > 95) {
				$result->addError(
					"Partial Close trigger ({$this->partialCloseTriggerPercent}%) must be between 10% and 95%."
				);
			}
		}

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
	// Parameter definitions
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 *
	 * @return AbstractStrategyParameter[]
	 */
	public static function getParameters(): array {
		return [
			new EntryVolume(),
			new StopLossPercent(),
			new TakeProfitPercent(),
			new UseIsolatedMargin(),
			new EMATrendFilter(),
			new EMATrendFilterTimeframe(),
			new EMAFilterPeriod(),
			new PartialCloseEnabled(),
			new PartialCloseTriggerPercent(),
			new PartialClosePercent(),
			new PartialCloseUseLimitOrder(),
			new BreakevenLockEnabled(),
			new BreakevenLockTriggerPercent(),
			new BreakevenLockClosePercent(),
			new BreakevenLockUseLimitOrder(),
			new StopLossCooldownMinutes(),
		];
	}

	// ------------------------------------------------------------------
	// Display
	// ------------------------------------------------------------------

	/**
	 * Get strategy parameters filtered for display.
	 * Hides breakeven-related params if the feature is disabled.
	 *
	 * @return array Filtered parameters.
	 */
	public function getDisplayParameters(): array {
		$excluded = [];
		if (!$this->partialCloseEnabled) {
			$excluded[] = PartialCloseTriggerPercent::getName();
			$excluded[] = PartialClosePercent::getName();
			$excluded[] = PartialCloseUseLimitOrder::getName();
		}
		if (!$this->breakevenLockEnabled) {
			$excluded[] = BreakevenLockTriggerPercent::getName();
			$excluded[] = BreakevenLockClosePercent::getName();
			$excluded[] = BreakevenLockUseLimitOrder::getName();
		}
		$filtered = array_diff_key($this->params, array_flip($excluded));
		$raw = [];
		foreach ($filtered as $name => $param) {
			$raw[$name] = $param->getRawValue();
		}
		return $raw;
	}

	// ------------------------------------------------------------------
	// EMA Trend Filter
	// ------------------------------------------------------------------

	/**
	 * Timeframes needed by single-entry strategies beyond the market's native TF.
	 * Pre-declares both 1D and 1H so the backtester loads candles for either filter.
	 *
	 * @return TimeFrameEnum[]
	 */
	public static function requiredTimeframes(): array {
		return [TimeFrameEnum::TF_1DAY, TimeFrameEnum::TF_1HOUR];
	}

	/**
	 * Record a stop-loss event so that the cooldown timer starts.
	 * Called by the backtester and live Market on SL closure.
	 */
	public function notifyStopLoss(int $timestamp): void {
		$this->lastStopLossTime = $timestamp;
	}

	/**
	 * @inheritDoc
	 *
	 * Checks stop-loss cooldown and EMA trend filter before delegating
	 * to the strategy-specific signal detection.
	 */
	public function shouldLong(): bool {
		if ($this->isStopLossCooldownActive()) {
			return false;
		}
		if ($this->emaTrendFilter && !$this->emaTrendIsUp()) {
			return false;
		}
		return $this->detectLongSignal();
	}

	/**
	 * @inheritDoc
	 *
	 * Checks stop-loss cooldown and EMA trend filter before delegating
	 * to the strategy-specific signal detection.
	 */
	public function shouldShort(): bool {
		if ($this->isStopLossCooldownActive()) {
			return false;
		}
		if ($this->emaTrendFilter && !$this->emaTrendIsDown()) {
			return false;
		}
		return $this->detectShortSignal();
	}

	private function isStopLossCooldownActive(): bool {
		if ($this->stopLossCooldownMinutes <= 0 || $this->lastStopLossTime === 0) {
			return false;
		}
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return false;
		}
		$currentTime = end($candles)->getOpenTime();
		return ($currentTime - $this->lastStopLossTime) < ($this->stopLossCooldownMinutes * 60);
	}

	/**
	 * Check if the trend on the filter timeframe is up (close > EMA).
	 */
	protected function emaTrendIsUp(): bool {
		$log = Logger::getLogger();
		$candles = $this->getFilterCandles();
		if ($candles === null || empty($candles)) {
			$log->debug("[EMA-FILTER↑] No filter candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);
		$ema = EMA::calculateFromPrices($closePrices, $this->emaFilterPeriod);
		if (empty($ema)) {
			$log->debug("[EMA-FILTER↑] EMA array empty");
			return false;
		}

		$latestEma = end($ema);
		$latestClose = end($closePrices);

		if ($latestClose <= $latestEma) {
			$log->debug(sprintf(
				"[EMA-FILTER↑] Trend is not up: close=%.8f <= EMA(%d)=%.8f [%s]",
				$latestClose, $this->emaFilterPeriod, $latestEma, $this->emaTrendFilterTimeframe,
			));
			return false;
		}

		return true;
	}

	/**
	 * Check if the trend on the filter timeframe is down (close < EMA).
	 */
	protected function emaTrendIsDown(): bool {
		$log = Logger::getLogger();
		$candles = $this->getFilterCandles();
		if ($candles === null || empty($candles)) {
			$log->debug("[EMA-FILTER↓] No filter candles available");
			return false;
		}

		$closePrices = array_map(fn($c) => $c->getClosePrice(), $candles);
		$ema = EMA::calculateFromPrices($closePrices, $this->emaFilterPeriod);
		if (empty($ema)) {
			$log->debug("[EMA-FILTER↓] EMA array empty");
			return false;
		}

		$latestEma = end($ema);
		$latestClose = end($closePrices);

		if ($latestClose >= $latestEma) {
			$log->debug(sprintf(
				"[EMA-FILTER↓] Trend is not down: close=%.8f >= EMA(%d)=%.8f [%s]",
				$latestClose, $this->emaFilterPeriod, $latestEma, $this->emaTrendFilterTimeframe,
			));
			return false;
		}

		return true;
	}

	/**
	 * Request candles for the EMA trend filter at the configured timeframe.
	 *
	 * @return ICandle[]|null Array of candles or null if not available.
	 */
	private function getFilterCandles(): ?array {
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return null;
		}
		$timeframe = TimeFrameEnum::from($this->emaTrendFilterTimeframe);
		$endTime = (int)end($candles)->getOpenTime();
		$startTime = $endTime - self::EMA_FILTER_CANDLES_COUNT * $timeframe->toSeconds();
		return $this->market->requestCandles($timeframe, $startTime, $endTime);
	}

	// ------------------------------------------------------------------
	// Abstract methods for concrete strategies
	// ------------------------------------------------------------------

	/**
	 * Detect a long entry signal using the strategy's indicator logic.
	 * Called after the EMA trend filter passes (if enabled).
	 */
	abstract protected function detectLongSignal(): bool;

	/**
	 * Detect a short entry signal using the strategy's indicator logic.
	 * Called after the EMA trend filter passes (if enabled).
	 */
	abstract protected function detectShortSignal(): bool;

	/**
	 * Whether this strategy opens long positions.
	 */
	abstract public function doesLong(): bool;

	/**
	 * Whether this strategy opens short positions.
	 */
	abstract public function doesShort(): bool;

	public static function getStrategySettingGroupTitle(): string {
		return 'Single Entry strategy settings';
	}
}
