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

/**
 * Base implementation of position interface.
 */
class StoredPosition extends SurrogatePKDatabaseRecord implements IStoredPosition {
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
	const string FSLOrderIdOnExchange = 'position_tp_order_id_on_exchange';

	/** Important timestamps */
	const string FCreatedAt = 'position_created_at';
	const string FUpdatedAt = 'position_updated_at';
	const string FFinishedAt = 'position_finished_at';

	/** TP and SL */
	const string FExpectedProfitPercent = 'position_expected_profit_percent';
	const string FExpectedTakeProfitPrice = 'position_expected_tp_price';

	/**
	 * Market.
	 * @var IMarket
	 */
	private IMarket $market;

	/**
	 * Reason of finishing the position. Always null if the position is still active.
	 * @var PositionFinishReasonEnum|null
	 */
	private ?PositionFinishReasonEnum $finishReason = null;

	/**
	 * Builds a Position object from a database row.
	 *
	 * @param Database $database
	 * @param array $row
	 * @param IMarket $market
	 */
	public function __construct(
		Database $database,
		array $row,
		IMarket $market /* passed as user data */
	) {
		// Link to the Market.
		$this->market = $market;

		// Build the parent.
		parent::__construct(
			$market->getDatabase(),
			$row,
			self::FId
		);

		// Prefix for column names.
		parent::setFieldNamePrefix('position');
	}

	/**
	 * Builds a Position object from a set of values.
	 *
	 * @param IMarket $market
	 * @param Money $volume
	 * @param PositionDirectionEnum $direction
	 * @param Money $entryPrice
	 * @param Money $currentPrice
	 * @param PositionStatusEnum $status
	 * @param string $exchangePositionId
	 * @return static
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
		return new self($market->getDatabase(), $row, $market);
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
		if ($this->getDirection()->isLong()) {
			$pnl = ($this->getCurrentPrice()->getAmount() - $this->getEntryPrice()->getAmount()) * $volume;
		} else {
			$pnl = ($this->getEntryPrice()->getAmount() - $this->getCurrentPrice()->getAmount()) * $volume;
		}

		return new Money($pnl, $this->getVolume()->getCurrency());
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
	public function getUnrealizedPnLPercent(): float {
		$entryPrice = $this->getEntryPrice();
		$currentPrice = $this->getCurrentPrice();
		$direction = ($this->getDirection()->isLong()) ? 1 : -1;
		$pnlPercent = $entryPrice->getPercentDifference($currentPrice) * $direction;
		return round($pnlPercent, 4);
	}

	/**
	 * @inheritDoc
	 */
	public function close(): void {
		// TODO: Implement close() method.
	}

	/**
	 * Get the market this position belongs to.
	 * @return IMarket Market instance.
	 */
	public function getMarket(): IMarket {
		return $this->market;
	}

	/**
	 * @inheritDoc
	 */
	public static function getTableName(): string {
		return 'positions';
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
	public function updateInfo(): bool {
		$market = $this->getMarket();
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
				$orderExists = $this->getMarket()->hasOrder($orderIdOnExchange);
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
		return Money::from($this->row[self::FExpectedTakeProfitPrice]);
	}

	/**
	 * @inheritDoc
	 */
	public function updateTakeProfit(): void {
		// This is the average entry point (aka the PnL line). Above this line we are at benefit.
		$averageEntryPrice = $this->getAverageEntryPrice();

		// This is the expected profit in %.
		$expectedProfitPercent = $this->getExpectedProfitPercent();

		// Current price of the take profit order.
		$currentTPPrice = $this->getTakeProfitPrice();

		// The expected price for the take profit order.
		$expectedTPPrice = $averageEntryPrice->modifyByPercent($expectedProfitPercent);

		// Difference between the current and the expected price.
		$diff = abs($currentTPPrice->getPercentDifference($expectedTPPrice));

		// If the prices are different enough, we should move the TP order.
		if ($diff > 0.1) {
			$this->getMarket()->setTakeProfit($expectedTPPrice);
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
