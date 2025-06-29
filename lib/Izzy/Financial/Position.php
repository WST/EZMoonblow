<?php

namespace Izzy\Financial;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;
use Izzy\System\Database\Database;
use Izzy\System\Database\ORM\SurrogatePKDatabaseRecord;
use Izzy\System\Logger;

/**
 * Base implementation of position interface.
 */
class Position extends SurrogatePKDatabaseRecord implements IPosition
{
	const string FId = 'position_id';
	const string FExchangeName = 'position_exchange_name';
	const string FTicker = 'position_ticker';
	const string FMarketType = 'position_market_type';
	const string FDirection = 'position_direction';
	const string FStatus = 'position_status';
	const string FIdOnExchange = 'position_id_on_exchange';
	const string FCurrentPrice = 'position_current_price';
	const string FEntryPrice = 'position_entry_price';
	const string FVolume = 'position_volume';
	const string FBaseCurrency = 'position_base_currency';
	const string FQuoteCurrency = 'position_quote_currency';
	
	/** Ids of the related orders on the Exchange */
	const string FEntryOrderIdOnExchange = 'position_entry_order_id_on_exchange';
	const string FTPOrderIdOnExchange = 'position_tp_order_id_on_exchange';
	const string FSLOrderIdOnExchange = 'position_tp_order_id_on_exchange';
	
	const string FCreatedAt = 'position_created_at';
	const string FUpdatedAt = 'position_updated_at';
	const string FFinishedAt = 'position_finished_at';

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
			self::FExchangeName => $market->getExchangeName(),
			self::FTicker => $market->getTicker(),
			self::FMarketType => $market->getMarketType()->toString(),
			self::FDirection => $direction->toString(),
			self::FEntryPrice => $entryPrice->getAmount(),
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
	public function getDirection(): PositionDirectionEnum {
		return PositionDirectionEnum::from($this->row[self::FDirection]);
	}

	/**
	 * @inheritDoc
	 */
	public function getEntryPrice(): Money {
		return Money::from($this->row[self::FEntryPrice], $this->row[self::FQuoteCurrency]);
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
		return (int) $this->row[self::FId];
	}

	/**
	 * @inheritDoc
	 */
	public function getIdOnExchange(): string {
		return $this->row[self::FIdOnExchange];
	}
	
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
		$this->row['position_current_price'] = $currentPrice->getAmount();
	}

	public function setUpdatedAt(string $date): void {
		$this->row['position_updated_at'] = $date;
	}

	/**
	 * Update position status.
	 *
	 * @param PositionStatusEnum $status New status.
	 * @return void
	 */
	public function setStatus(PositionStatusEnum $status): void {
		$this->row['position_status'] = $status->value;
	}

	public function getUnrealizedPnLPercent(): float {
		// TODO: Implement getUnrealizedPnLPercent() method.
	}

	public function close(): void {
		// TODO: Implement close() method.
	}

	public function getMarket(): IMarket {
		return $this->market;
	}

	/**
	 * @inheritDoc
	 */
	public static function getTableName(): string {
		return 'positions';
	}

	public function getMarketType(): MarketTypeEnum {
		// TODO: Implement getMarketType() method.
	}

	/**
	 * @param Money $dcaAmount
	 * @return void
	 */
	public function buyAdditional(Money $dcaAmount): void {
		Logger::getLogger()->warning("DCA AVERAGING");
	}
	
	public function updateInfo(): bool {
		/* FOR SPOT
		 * --------------------------------------------------------------------------------------------
		 * 7. If the status is open and the balance of the base currency is less than stored,
		 *    we set the position status to “finished”, update the information and return the position.
		 * 8. If the status is open and the balance is greater or equals stored, we update the price info
		 *    and return the position.
		 * --------------------------------------------------------------------------------------------
		 * 9. If the status is finished, we return false.
		 */
		$market = $this->getMarket();
		$exchange = $market->getExchange();
		$currentPrice = $exchange->getCurrentPrice($market);
		
		// Get current position status.
		$currentStatus = $this->getStatus();
		
		// If the status is pending, check the presence of a “buy” limit order on the exchange.
		if ($currentStatus->isPending()) {
			$orderIdOnExchange = $this->getEntryOrderIdOnExchange();
			$orderExists = $this->getMarket()->hasOrder($orderIdOnExchange);

			/*
			 * If the order does not exist, we need to check the balance of the base currency on the exchange
			 * to ensure successful execution of the order.
			 * ^ TODO, for now we just turn the position into open status.
			 */
			if (!$orderExists) {
				$this->setStatus(PositionStatusEnum::OPEN);
			}
		}
		
		// If the status is open, TODO.
		
		// Whatever we did, we need to update current price and update time.
		$this->setCurrentPrice($currentPrice);
		$this->setUpdatedAt(time());
		
		// Save the changes.
		return self::save();
	}
}
