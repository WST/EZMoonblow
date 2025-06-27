<?php

namespace Izzy\Financial;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;

/**
 * Base implementation of position interface.
 */
class Position implements IPosition
{
	private IMarket $market;
	
	/**
	 * Position volume.
	 */
	private Money $volume;

	/**
	 * Position direction: 'long' or 'short'.
	 */
	private PositionDirectionEnum $direction;

	/**
	 * Entry price of the position.
	 */
	private Money $entryPrice;

	/**
	 * Current market price.
	 */
	private Money $currentPrice;

	/**
	 * Position status.
	 */
	private PositionStatusEnum $status;

	/**
	 * Position ID from exchange.
	 */
	private string $positionId;

	/**
	 * Reason of finishing the position. Always null if the position is still active.
	 * @var PositionFinishReasonEnum|null 
	 */
	private ?PositionFinishReasonEnum $finishReason = null;

	/**
	 * Constructor.
	 *
	 * @param Money $volume Position volume.
	 * @param PositionDirectionEnum $direction Position direction.
	 * @param Money $entryPrice Entry price.
	 * @param Money $currentPrice Current market price.
	 * @param PositionStatusEnum $status Position status.
	 * @param string $positionId Position ID from exchange.
	 */
	public function __construct(
		IMarket $market,
		Money $volume,
		PositionDirectionEnum $direction,
		Money $entryPrice,
		Money $currentPrice,
		PositionStatusEnum $status,
		string $positionId
	) {
		$this->market = $market;
		$this->volume = $volume;
		$this->direction = $direction;
		$this->entryPrice = $entryPrice;
		$this->currentPrice = $currentPrice;
		$this->status = $status;
		$this->positionId = $positionId;
	}

	/**
	 * @inheritDoc
	 */
	public function getVolume(): Money {
		return $this->volume;
	}

	/**
	 * @inheritDoc
	 */
	public function getDirection(): PositionDirectionEnum {
		return $this->direction;
	}

	/**
	 * @inheritDoc
	 */
	public function getEntryPrice(): Money {
		return $this->entryPrice;
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): Money {
		return $this->currentPrice;
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnL(): Money {
		$volume = $this->volume->getAmount();
		
		if ($this->direction->isLong()) {
			$pnl = ($this->currentPrice->getAmount() - $this->entryPrice->getAmount()) * $volume;
		} else {
			$pnl = ($this->entryPrice->getAmount() - $this->currentPrice->getAmount()) * $volume;
		}

		return new Money($pnl, $this->volume->getCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getStatus(): PositionStatusEnum {
		return $this->status;
	}

	/**
	 * @inheritDoc
	 */
	public function isOpen(): bool {
		return $this->status->isOpen();
	}

	/**
	 * @inheritDoc
	 */
	public function isActive(): bool {
		return $this->status->isOpen() || $this->status->isPending();
	}

	/**
	 * @inheritDoc
	 */
	public function getPositionId(): string {
		return $this->positionId;
	}

	/**
	 * Update current price.
	 *
	 * @param Money $currentPrice New current price.
	 * @return void
	 */
	public function updateCurrentPrice(Money $currentPrice): void {
		$this->currentPrice = $currentPrice;
	}

	/**
	 * Update position status.
	 *
	 * @param PositionStatusEnum $status New status.
	 * @return void
	 */
	public function updateStatus(PositionStatusEnum $status): void {
		$this->status = $status;
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
