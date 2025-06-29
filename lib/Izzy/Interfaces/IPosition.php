<?php

namespace Izzy\Interfaces;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\Money;

/**
 * Represents currently open position.
 */
interface IPosition
{
	/**
	 * Get current position volume.
	 * @return Money
	 */
	public function getVolume(): Money;

	/**
	 * Get position direction.
	 * @return PositionDirectionEnum
	 */
	public function getDirection(): PositionDirectionEnum;

	/**
	 * Get entry price of the position.
	 * @return Money
	 */
	public function getEntryPrice(): Money;

	/**
	 * Get current market price.
	 * @return Money
	 */
	public function getCurrentPrice(): Money;

	/**
	 * Get unrealized profit/loss.
	 * @return Money
	 */
	public function getUnrealizedPnL(): Money;

	/**
	 * Get unrealized profit/loss in percent of the position size (not the margin).
	 * @return float
	 */
	public function getUnrealizedPnLPercent(): float;

	/**
	 * Get position status: 'open', 'closed', 'pending'.
	 * @return PositionStatusEnum
	 */
	public function getStatus(): PositionStatusEnum;

	/**
	 * Check if position is open.
	 * @return bool
	 */
	public function isOpen(): bool;

	/**
	 * Izzy’s internal Position identifier.
	 * @return int
	 */
	public function getPositionId(): int;

	/**
	 * Market close the position.
	 * @return void
	 */
	public function close(): void;

	public function getMarketType(): MarketTypeEnum;

	public function buyAdditional(Money $dcaAmount);
}
