<?php

namespace Izzy\Financial;

use Izzy\Interfaces\IPosition;

/**
 * Base implementation of position interface.
 */
class Position implements IPosition
{
	/**
	 * Position volume.
	 */
	private Money $volume;

	/**
	 * Position direction: 'long' or 'short'.
	 */
	private string $direction;

	/**
	 * Entry price of the position.
	 */
	private float $entryPrice;

	/**
	 * Current market price.
	 */
	private float $currentPrice;

	/**
	 * Position status: 'open', 'closed', 'pending'.
	 */
	private string $status;

	/**
	 * Position ID from exchange.
	 */
	private string $positionId;

	/**
	 * Constructor.
	 * 
	 * @param Money $volume Position volume.
	 * @param string $direction Position direction.
	 * @param float $entryPrice Entry price.
	 * @param float $currentPrice Current market price.
	 * @param string $status Position status.
	 * @param string $positionId Position ID from exchange.
	 */
	public function __construct(
		Money $volume,
		string $direction,
		float $entryPrice,
		float $currentPrice,
		string $status,
		string $positionId
	) {
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
	public function getVolume(): Money
	{
		return $this->volume;
	}

	/**
	 * @inheritDoc
	 */
	public function getDirection(): string
	{
		return $this->direction;
	}

	/**
	 * @inheritDoc
	 */
	public function getEntryPrice(): float
	{
		return $this->entryPrice;
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): float
	{
		return $this->currentPrice;
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnL(): Money
	{
		$volume = $this->volume->getAmount();
		
		if ($this->direction === 'long') {
			$pnl = ($this->currentPrice - $this->entryPrice) * $volume;
		} else {
			$pnl = ($this->entryPrice - $this->currentPrice) * $volume;
		}

		return new Money($pnl, $this->volume->getCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getStatus(): string
	{
		return $this->status;
	}

	/**
	 * @inheritDoc
	 */
	public function isOpen(): bool
	{
		return $this->status === 'open';
	}

	/**
	 * @inheritDoc
	 */
	public function getPositionId(): string
	{
		return $this->positionId;
	}

	/**
	 * Update current price.
	 * 
	 * @param float $currentPrice New current price.
	 * @return void
	 */
	public function updateCurrentPrice(float $currentPrice): void
	{
		$this->currentPrice = $currentPrice;
	}

	/**
	 * Update position status.
	 * 
	 * @param string $status New status.
	 * @return void
	 */
	public function updateStatus(string $status): void
	{
		$this->status = $status;
	}
} 