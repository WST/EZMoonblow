<?php

namespace Izzy\Interfaces;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;

/**
 * Base interface for all positions.
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
	 * Get the average entry price of the position.
	 * @return Money
	 */
	public function getAverageEntryPrice(): Money;

	/**
	 * Get current price of the base currency in quote currency.
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
}
