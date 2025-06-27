<?php

namespace Izzy\Financial;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;
use Izzy\System\Database\SurrogatePKDatabaseRecord;

/**
 * Base implementation of position interface.
 */
class Position extends SurrogatePKDatabaseRecord implements IPosition
{
	private string $tableName = 'positions';
	
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
	 * @param IMarket $market
	 * @param array $row
	 */
	public function __construct(
		IMarket $market,
		array $row = [],
	) {
		// Link to the Market.
		$this->market = $market;
		
		// Build the parent.
		parent::__construct(
			$market->getDatabase(),
			$this->tableName,
			$row, 
			'id'
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
		$row = [];
		return new self($market, $row);
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
}
