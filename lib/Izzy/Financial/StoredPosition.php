<?php

namespace Izzy\Financial;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;
use Izzy\System\Database\Database;
use Izzy\System\Database\ORM\SurrogatePKDatabaseRecord;
use Izzy\System\Logger;
use Izzy\Traits\PositionTrait;

/**
 * Stored position in the local database.
 */
class StoredPosition extends SurrogatePKDatabaseRecord implements IStoredPosition
{
	use PositionTrait;

	/** Position attributes */
	const string FId = 'position_id';
	const string FExchangeName = 'position_exchange_name';
	const string FTicker = 'position_ticker';
	const string FMarketType = 'position_market_type';
	const string FDirection = 'position_direction';
	const string FStatus = 'position_status';
	const string FIdOnExchange = 'position_id_on_exchange';
	const string FVolume = 'position_volume';
	const string FBaseCurrency = 'position_base_currency';
	const string FQuoteCurrency = 'position_quote_currency';

	/** Prices */
	const string FInitialEntryPrice = 'position_initial_entry_price';
	const string FAverageEntryPrice = 'position_average_entry_price';
	const string FCurrentPrice = 'position_current_price';

	/** Ids of the related orders on the Exchange */
	const string FEntryOrderIdOnExchange = 'position_entry_order_id_on_exchange';
	const string FTPOrderIdOnExchange = 'position_tp_order_id_on_exchange';
	const string FSLOrderIdOnExchange = 'position_sl_order_id_on_exchange';

	/** Important timestamps */
	const string FCreatedAt = 'position_created_at';
	const string FUpdatedAt = 'position_updated_at';
	const string FFinishedAt = 'position_finished_at';

	/** TP and SL */
	const string FExpectedProfitPercent = 'position_expected_profit_percent';
	const string FExpectedTakeProfitPrice = 'position_expected_tp_price';
	const string FExpectedStopLossPercent = 'position_expected_sl_percent';
	const string FStopLossPrice = 'position_stop_loss_price';
	const string FFinishReason = 'position_finish_reason';

	/**
	 * Builds a Position object from a database row.
	 *
	 * @param Database $database Database connection.
	 * @param array $row Database row.
	 */
	public function __construct(Database $database, array $row) {
		parent::__construct($database, $row, self::FId);
		parent::setFieldNamePrefix('position');
	}

	/**
	 * Builds a Position object from a set of values.
	 *
	 * @param IMarket $market Market for this position.
	 * @param Money $volume Position volume.
	 * @param PositionDirectionEnum $direction Position direction.
	 * @param Money $entryPrice Entry price.
	 * @param Money $currentPrice Current price.
	 * @param PositionStatusEnum $status Position status.
	 * @param string $exchangePositionId Exchange position ID.
	 * @return static New position instance.
	 */
	public static function create(
		IMarket $market,
		Money $volume,
		PositionDirectionEnum $direction,
		Money $entryPrice,
		Money $currentPrice,
		PositionStatusEnum $status,
		string $exchangePositionId
	): static {
		$now = time();
		$row = [
			self::FExchangeName => $market->getExchange()->getName(),
			self::FTicker => $market->getTicker(),
			self::FMarketType => $market->getMarketType()->toString(),
			self::FDirection => $direction->toString(),
			self::FInitialEntryPrice => $entryPrice->getAmount(),
			self::FCurrentPrice => $currentPrice->getAmount(),
			self::FVolume => $volume->getAmount(),
			self::FBaseCurrency => $market->getPair()->getBaseCurrency(),
			self::FQuoteCurrency => $market->getPair()->getQuoteCurrency(),
			self::FStatus => $status->toString(),
			self::FIdOnExchange => $exchangePositionId,
			self::FEntryOrderIdOnExchange => $exchangePositionId,
			self::FCreatedAt => $now,
			self::FUpdatedAt => $now,
		];
		return new self($market->getDatabase(), $row);
	}

	/**
	 * Get default ORDER BY clause for loading positions.
	 * @return string ORDER BY clause.
	 */
	private static function getDefaultOrder(): string {
		return PositionStatusEnum::getSqlSortOrder(self::FStatus) . ', ' . self::FUpdatedAt . ' DESC';
	}

	/**
	 * Load all positions from database.
	 *
	 * @param Database $database Database connection.
	 * @param string|null $orderBy Optional ORDER BY clause.
	 * @return static[] Array of StoredPosition objects.
	 */
	public static function loadAll(Database $database, ?string $orderBy = null): array {
		return $database->selectAllObjects(self::class, [], $orderBy ?? self::getDefaultOrder());
	}

	/**
	 * Count positions by status.
	 *
	 * @param Database $database Database connection.
	 * @return array<string, int> Statistics with counts by status.
	 */
	public static function getStatistics(Database $database): array {
		$stats = [
			'total' => 0,
			'open' => 0,
			'pending' => 0,
			'finished' => 0,
			'cancelled' => 0,
			'error' => 0,
		];

		$positions = self::loadAll($database);
		foreach ($positions as $position) {
			$stats['total']++;
			$status = $position->getStatus();

			if ($status->isOpen()) {
				$stats['open']++;
			} elseif ($status->isPending()) {
				$stats['pending']++;
			} elseif ($status->isFinished()) {
				$stats['finished']++;
			} elseif ($status->isCanceled()) {
				$stats['cancelled']++;
			} elseif ($status->isError()) {
				$stats['error']++;
			}
		}

		return $stats;
	}

	/**
	 * @inheritDoc
	 */
	public function getVolume(): Money {
		return Money::from($this->row[self::FVolume]);
	}

	/**
	 * @inheritDoc
	 */
	public function setVolume(Money $volume): void {
		$this->row[self::FVolume] = $volume->getAmount();
	}

	/**
	 * @inheritDoc
	 */
	public function getDirection(): PositionDirectionEnum {
		return PositionDirectionEnum::from($this->row[self::FDirection]);
	}

	/**
	 * @inheritDoc
	 */
	public function getEntryPrice(): Money {
		return Money::from($this->row[self::FInitialEntryPrice], $this->row[self::FQuoteCurrency]);
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): Money {
		return Money::from($this->row[self::FCurrentPrice], $this->row[self::FQuoteCurrency]);
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnL(): Money {
		$volume = $this->getVolume()->getAmount();
		$referencePrice = $this->getPriceForPnL()->getAmount();
		$currentPrice = $this->getCurrentPrice()->getAmount();
		
		if ($this->getDirection()->isLong()) {
			$pnl = ($currentPrice - $referencePrice) * $volume;
		} else {
			$pnl = ($referencePrice - $currentPrice) * $volume;
		}

		return new Money($pnl, $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getStatus(): PositionStatusEnum {
		return PositionStatusEnum::from($this->row[self::FStatus]);
	}

	/**
	 * @inheritDoc
	 */
	public function isOpen(): bool {
		return $this->getStatus()->isOpen();
	}

	/**
	 * @inheritDoc
	 */
	public function isActive(): bool {
		$status = $this->getStatus();
		return $status->isOpen() || $status->isPending();
	}

	/**
	 * @inheritDoc
	 */
	public function getPositionId(): int {
		return (int)$this->row[self::FId];
	}

	/**
	 * @inheritDoc
	 */
	public function getIdOnExchange(): string {
		return $this->row[self::FIdOnExchange];
	}

	/**
	 * Get the entry order ID on the exchange.
	 * @return string Entry order ID.
	 */
	public function getEntryOrderIdOnExchange(): string {
		return $this->row[self::FEntryOrderIdOnExchange];
	}

	/**
	 * Update current price.
	 *
	 * @param Money $currentPrice New current price.
	 * @return void
	 */
	public function setCurrentPrice(Money $currentPrice): void {
		$this->row[self::FCurrentPrice] = $currentPrice->getAmount();
	}

	/**
	 * Set the last update timestamp.
	 * @param string|int $date Timestamp or date string.
	 * @return void
	 */
	public function setCreatedAt(string|int $date): void {
		$this->row[self::FCreatedAt] = $date;
	}

	public function setUpdatedAt(string|int $date): void {
		$this->row[self::FUpdatedAt] = $date;
	}

	/**
	 * Update position status.
	 *
	 * @param PositionStatusEnum $status New status.
	 * @return void
	 */
	public function setStatus(PositionStatusEnum $status): void {
		$this->row[self::FStatus] = $status->value;
	}

	/**
	 * @inheritDoc
	 */
	public function close(): void {
		// TODO: Implement close() method.
	}

	/**
	 * @inheritDoc
	 */
	public static function getTableName(): string {
		return 'positions';
	}

	/**
	 * Get exchange name for this position.
	 * @return string Exchange name.
	 */
	public function getExchangeName(): string {
		return $this->row[self::FExchangeName];
	}

	/**
	 * Get ticker symbol for this position.
	 * @return string Ticker symbol.
	 */
	public function getTicker(): string {
		return $this->row[self::FTicker];
	}

	/**
	 * Get base currency for this position.
	 * @return string Base currency code.
	 */
	public function getBaseCurrency(): string {
		return $this->row[self::FBaseCurrency];
	}

	/**
	 * Get quote currency for this position.
	 * @return string Quote currency code.
	 */
	public function getQuoteCurrency(): string {
		return $this->row[self::FQuoteCurrency];
	}

	/**
	 * Get position creation timestamp.
	 * @return int Unix timestamp.
	 */
	public function getCreatedAt(): int {
		return (int)($this->row[self::FCreatedAt] ?? 0);
	}

	/**
	 * Get position last update timestamp.
	 * @return int Unix timestamp.
	 */
	public function getUpdatedAt(): int {
		return (int)($this->row[self::FUpdatedAt] ?? 0);
	}

	/**
	 * Get position finish timestamp.
	 * @return int Unix timestamp (0 if not finished).
	 */
	public function getFinishedAt(): int {
		return (int)($this->row[self::FFinishedAt] ?? 0);
	}

	/**
	 * Format a Unix timestamp for display.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @param string $format Date format.
	 * @return string Formatted date or '-' if timestamp is 0.
	 */
	public static function formatTimestamp(int $timestamp, string $format = 'Y-m-d H:i'): string {
		return $timestamp > 0 ? date($format, $timestamp) : '-';
	}

	/**
	 * Get formatted creation date.
	 * @param string $format Date format.
	 * @return string Formatted date or '-'.
	 */
	public function getCreatedAtFormatted(string $format = 'Y-m-d H:i'): string {
		return self::formatTimestamp($this->getCreatedAt(), $format);
	}

	/**
	 * Get formatted last update date.
	 * @param string $format Date format.
	 * @return string Formatted date or '-'.
	 */
	public function getUpdatedAtFormatted(string $format = 'Y-m-d H:i'): string {
		return self::formatTimestamp($this->getUpdatedAt(), $format);
	}

	/**
	 * Get formatted finish date.
	 * @param string $format Date format.
	 * @return string Formatted date or '-'.
	 */
	public function getFinishedAtFormatted(string $format = 'Y-m-d H:i'): string {
		return self::formatTimestamp($this->getFinishedAt(), $format);
	}

	/**
	 * @inheritDoc
	 */
	public function getMarketType(): MarketTypeEnum {
		return MarketTypeEnum::from($this->row[self::FMarketType]);
	}

	/**
	 * @inheritDoc
	 */
	public function buyAdditional(Money $dcaAmount): void {
		Logger::getLogger()->warning("DCA AVERAGING");
	}

	/**
	 * @inheritDoc
	 */
	public function updateInfo(IMarket $market): bool {
		$exchange = $market->getExchange();

		// Get current position status.
		$currentStatus = $this->getStatus();
		$currentPrice = $exchange->getCurrentPrice($market);

		Logger::getLogger()->debug("Updating stored position info for $market");

		/**
		 * Spot market. Positions are emulated.
		 */
		if ($this->getMarketType()->isSpot()) {
			$baseCurrency = $market->getPair()->getBaseCurrency();
			$currentAmountOfBaseCurrency = $exchange->getSpotBalanceByCurrency($baseCurrency);

			// Always update current price for spot markets.
			$this->setCurrentPrice($currentPrice);

			// If the status is pending, check the presence of a “buy” limit order on the exchange.
			if ($currentStatus->isPending()) {
				$orderIdOnExchange = $this->getEntryOrderIdOnExchange();
				$orderExists = $market->hasOrder($orderIdOnExchange);
				if (!$orderExists) {
					$this->setStatus(PositionStatusEnum::OPEN);
					// When position becomes open, update average entry price to current price.
					$this->setAverageEntryPrice($currentPrice);
				}
			}

			// If the status is open, check if the position is finished.
			if ($currentStatus->isOpen()) {
				$positionVolume = $this->getVolume();
				if ($currentAmountOfBaseCurrency->isLessThan($positionVolume)) {
					// The whole amount of the base currency was sold.
					$this->setStatus(PositionStatusEnum::FINISHED);
					$this->setFinishedAt(time());
				} else {
					// Position is still open, update average entry price based on current holdings.
					// This handles DCA scenarios where additional purchases change the average price.
					$currentHoldings = $currentAmountOfBaseCurrency->getAmount();
					$originalVolume = $positionVolume->getAmount();
					
					if ($currentHoldings > $originalVolume) {
						// Additional purchases made (DCA), recalculate average entry price.
						// This is a simplified calculation - in reality, we'd need to track individual purchases.
						$entryPrice = $this->getEntryPrice()->getAmount();
						$currentPriceAmount = $currentPrice->getAmount();
						$additionalAmount = $currentHoldings - $originalVolume;
						
						// Weighted average: (original * entry + additional * current) / total
						$totalVolume = $currentHoldings;
						$weightedAveragePrice = (($originalVolume * $entryPrice) + ($additionalAmount * $currentPriceAmount)) / $totalVolume;
						
						$this->setAverageEntryPrice(Money::from($weightedAveragePrice, $currentPrice->getCurrency()));
						$this->setVolume(Money::from($currentHoldings, $positionVolume->getCurrency()));
					}
				}
			}
		}

		/**
		 * Futures. Positions are real.
		 */
		if ($this->getMarketType()->isFutures()) {
			// Always update current price for futures markets.
			$this->setCurrentPrice($currentPrice);

			if ($currentStatus->isPending()) {
				// To turn Pending into Open, we need to ensure that the entry order was executed.
				if (!$exchange->hasActiveOrder($market, $this->getEntryOrderIdOnExchange())) {
					Logger::getLogger()->debug("Entry order not found for $market, turning the position OPEN");
					$this->setStatus(PositionStatusEnum::OPEN);
					
					// Get the actual position from exchange to update our stored data.
					$positionOnExchange = $exchange->getCurrentFuturesPosition($market);
					if ($positionOnExchange) {
						$this->setAverageEntryPrice($positionOnExchange->getAverageEntryPrice());
						$this->setVolume($positionOnExchange->getVolume());
					}
				} else {
					/**
					 * If the price went away too far, cancel the position entry.
					 * NOTE: there is a possibility of the price changing so quick that the DCA order gets executed
					 * instead of the entry order. We don't handle such case, but it should be fixed some day.
					 */
					$priceDifference = abs($this->getEntryPrice()->getPercentDifference($currentPrice));
					if ($priceDifference > 0.5) {
						if ($market->removeLimitOrders()) {
							$this->setStatus(PositionStatusEnum::CANCELED);
							$this->setFinishedAt(time());
						} else {
							Logger::getLogger()->error("Failed to cancel limit orders for $market");
						}
					}
				}
			}

			if ($currentStatus->isOpen()) {
				// To turn Open into Finished, we need to ensure that the position on the Exchange is finished.
				$positionOnExchange = $exchange->getCurrentFuturesPosition($market);
				if (!$positionOnExchange) {
					if ($market->removeLimitOrders()) {
						$this->setStatus(PositionStatusEnum::FINISHED);
						$this->setFinishedAt(time());
					} else {
						Logger::getLogger()->error("Failed to cancel limit orders for $market");
					}
				} else {
					// Position still exists on exchange, update all relevant data.
					$this->setCurrentPrice($positionOnExchange->getCurrentPrice());
					$this->setAverageEntryPrice($positionOnExchange->getAverageEntryPrice());
					$this->setVolume($positionOnExchange->getVolume());
				}
			}
		}

		// Whatever we did, we need to update the update time.
		$this->setUpdatedAt(time());

		// Save the changes.
		return self::save();
	}

	/**
	 * @inheritDoc
	 */
	public function sellAdditional(Money $dcaAmount): void {
		// TODO: Implement sellAdditional() method.
	}

	private function setFinishedAt(int $time): void {
		$this->row[self::FFinishedAt] = $time;
	}

	/**
	 * Mark the position as finished (e.g. when TP is hit in backtest).
	 *
	 * @param int $finishedAt Unix timestamp when the position was closed.
	 */
	public function markFinished(int $finishedAt): void {
		$this->setStatus(PositionStatusEnum::FINISHED);
		$this->row[self::FFinishedAt] = $finishedAt;
	}

	/**
	 * @inheritDoc
	 */
	public function setExpectedProfitPercent(float $expectedProfitPercent): void {
		$this->row[self::FExpectedProfitPercent] = $expectedProfitPercent;
	}

	/**
	 * @inheritDoc
	 */
	public function getExpectedProfitPercent(): float {
		return floatval($this->row[self::FExpectedProfitPercent]);
	}

	/**
	 * Set the take profit price.
	 * @param Money|null $price Take profit price, or null to clear.
	 * @return void
	 */
	public function setTakeProfitPrice(?Money $price): void {
		$this->row[self::FExpectedTakeProfitPrice] = $price?->getAmount();
	}

	/**
	 * Get the take profit price.
	 * @return Money|null Take profit price, or null if not set.
	 */
	public function getTakeProfitPrice(): ?Money {
		$value = $this->row[self::FExpectedTakeProfitPrice] ?? null;
		return $value !== null ? Money::from($value) : null;
	}

	/**
	 * @inheritDoc
	 */
	public function getStopLossPrice(): ?Money {
		$value = $this->row[self::FStopLossPrice] ?? null;
		return $value !== null ? Money::from($value) : null;
	}

	/**
	 * @inheritDoc
	 */
	public function setStopLossPrice(?Money $price): void {
		$this->row[self::FStopLossPrice] = $price?->getAmount();
	}

	/**
	 * @inheritDoc
	 */
	public function getExpectedStopLossPercent(): float {
		return floatval($this->row[self::FExpectedStopLossPercent] ?? 0);
	}

	/**
	 * @inheritDoc
	 */
	public function setExpectedStopLossPercent(float $expectedStopLossPercent): void {
		$this->row[self::FExpectedStopLossPercent] = $expectedStopLossPercent;
	}

	/**
	 * @inheritDoc
	 */
	public function updateStopLoss(IMarket $market): void {
		$averageEntryPrice = $this->getAverageEntryPrice();
		if ($averageEntryPrice === null || $averageEntryPrice->getAmount() <= 0) {
			return;
		}

		// Do not set SL when expected percentage is zero.
		$expectedSLPercent = $this->getExpectedStopLossPercent();
		if (abs($expectedSLPercent) < 0.0001) {
			return;
		}

		// SL is on the losing side: opposite to TP direction.
		// For LONG, SL is below entry; for SHORT, SL is above entry.
		$direction = $this->getDirection();
		$expectedSLPrice = $averageEntryPrice->modifyByPercentWithDirection(-$expectedSLPercent, $direction);

		// Sanity check: SL price must be positive.
		if ($expectedSLPrice->getAmount() <= 0) {
			Logger::getLogger()->warning(
				"Skipping SL update for {$this->getTicker()}: computed SL price would be non-positive "
				. "(entry={$averageEntryPrice->getAmount()}, direction={$direction->value}, percent={$expectedSLPercent})"
			);
			return;
		}

		// Current SL price on the exchange (may be unset on first update).
		$currentSLPrice = $this->getStopLossPrice();
		if ($currentSLPrice === null) {
			$market->setStopLoss($expectedSLPrice);
			$this->setStopLossPrice($expectedSLPrice);
			return;
		}

		// If the prices are different enough, we should move the SL order.
		$diff = abs($currentSLPrice->getPercentDifference($expectedSLPrice));
		if ($diff > 0.1) {
			$market->setStopLoss($expectedSLPrice);
			$this->setStopLossPrice($expectedSLPrice);
		}
	}

	/**
	 * Get the finish reason for this position.
	 * @return PositionFinishReasonEnum|null Finish reason, or null if not finished or unknown.
	 */
	public function getFinishReason(): ?PositionFinishReasonEnum {
		$value = $this->row[self::FFinishReason] ?? null;
		if ($value === null) {
			return null;
		}
		return PositionFinishReasonEnum::tryFrom($value);
	}

	/**
	 * Set the finish reason for this position.
	 * @param PositionFinishReasonEnum|null $reason Finish reason, or null to clear.
	 * @return void
	 */
	public function setFinishReason(?PositionFinishReasonEnum $reason): void {
		$this->row[self::FFinishReason] = $reason?->value;
	}

	/**
	 * @inheritDoc
	 */
	public function updateTakeProfit(IMarket $market): void {
		// This is the average entry point (aka the PnL line). Above this line we are at benefit.
		$averageEntryPrice = $this->getAverageEntryPrice();
		if ($averageEntryPrice === null || $averageEntryPrice->getAmount() <= 0) {
			return;
		}

		// Do not set TP when expected profit is zero (e.g. grid levels without TP); avoids false "TP hit" with 0 PnL.
		$expectedProfitPercent = $this->getExpectedProfitPercent();
		if (abs($expectedProfitPercent) < 0.0001) {
			return;
		}

		// This is the expected profit in % (positive number).
		// For LONG, TP is above entry; for SHORT, TP is below entry.
		$direction = $this->getDirection();
		$expectedTPPrice = $averageEntryPrice->modifyByPercentWithDirection($expectedProfitPercent, $direction);

		// Sanity check: TP price must be positive (price of an instrument cannot be negative).
		if ($expectedTPPrice->getAmount() <= 0) {
			Logger::getLogger()->warning("Skipping TP update for {$this->getTicker()}: computed TP price would be non-positive (entry={$averageEntryPrice->getAmount()}, direction={$direction->value}, percent={$expectedProfitPercent})");
			return;
		}

		// Current price of the take profit order (may be unset on first update).
		$currentTPPrice = $this->getTakeProfitPrice();
		if ($currentTPPrice === null) {
			$market->setTakeProfit($expectedTPPrice);
			$this->setTakeProfitPrice($expectedTPPrice);
			return;
		}

		// Difference between the current and the expected price.
		$diff = abs($currentTPPrice->getPercentDifference($expectedTPPrice));

		// If the prices are different enough, we should move the TP order.
		if ($diff > 0.1) {
			$market->setTakeProfit($expectedTPPrice);
			$this->setTakeProfitPrice($expectedTPPrice);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getAverageEntryPrice(): Money {
		return Money::from($this->row[self::FAverageEntryPrice]);
	}

	/**
	 * Set the average entry price.
	 * @param Money $averageEntryPrice New average entry price.
	 * @return void
	 */
	public function setAverageEntryPrice(Money $averageEntryPrice): void {
		$this->row[self::FAverageEntryPrice] = $averageEntryPrice->getAmount();
	}

}
