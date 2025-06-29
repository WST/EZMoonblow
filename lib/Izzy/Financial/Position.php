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
			'position_id'
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
			'position_exchange_name' => $market->getExchangeName(),
			'position_ticker' => $market->getTicker(),
			'position_market_type' => $market->getMarketType()->toString(),
			'position_direction' => $direction->toString(),
			'position_entry_price' => $entryPrice->getAmount(),
			'position_current_price' => $currentPrice->getAmount(),
			'position_volume' => $volume->getAmount(),
			'position_base_currency' => $market->getPair()->getBaseCurrency(),
			'position_quote_currency' => $market->getPair()->getQuoteCurrency(),
			'position_status' => $status->toString(),
			'position_id_on_exchange' => $exchangePositionId,
			'position_order_id' => $exchangePositionId,
			'position_created_at' => $now,
			'position_updated_at' => $now,
		];
		return new self($market->getDatabase(), $row, $market);
	}

	/**
	 * @inheritDoc
	 */
	public function getVolume(): Money {
		return Money::from($this->row['position_volume']);
	}

	/**
	 * @inheritDoc
	 */
	public function getDirection(): PositionDirectionEnum {
		return PositionDirectionEnum::from($this->row['position_direction']);
	}

	/**
	 * @inheritDoc
	 */
	public function getEntryPrice(): Money {
		return Money::from($this->row['position_entry_price'], $this->row['position_quote_currency']);
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): Money {
		return Money::from($this->row['position_current_price'], $this->row['position_quote_currency']);
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
		return PositionStatusEnum::from($this->row['position_status']);
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
		return (int) $this->row['position_id'];
	}

	/**
	 * @inheritDoc
	 */
	public function getExchangePositionId(): string {
		return (int) $this->row['position_id_on_exchange'];
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
		$market = $this->getMarket();
		$exchange = $market->getExchange();
		$currentPrice = $exchange->getCurrentPrice($market);
		$this->setCurrentPrice($currentPrice);
		$this->setUpdatedAt(time());
		return self::save();
	}
}
