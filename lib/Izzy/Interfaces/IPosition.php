<?php

namespace Izzy\Interfaces;

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
	 * Get position direction: 'long' or 'short'.
	 * @return string
	 */
	public function getDirection(): string;

	/**
	 * Get entry price of the position.
	 * @return float
	 */
	public function getEntryPrice(): float;

	/**
	 * Get current market price.
	 * @return float
	 */
	public function getCurrentPrice(): float;

	/**
	 * Get unrealized profit/loss.
	 * @return Money
	 */
	public function getUnrealizedPnL(): Money;

	/**
	 * Get position status: 'open', 'closed', 'pending'.
	 * @return string
	 */
	public function getStatus(): string;

	/**
	 * Check if position is open.
	 * @return bool
	 */
	public function isOpen(): bool;

	/**
	 * Get position ID from exchange.
	 * @return string
	 */
	public function getPositionId(): string;
}
