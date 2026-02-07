<?php

namespace Izzy\Interfaces;

use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\Money;

/**
 * Represents a position stored in the local database.
 *
 * This interface extends IPosition with persistence and management capabilities
 * specific to positions tracked by EZMoonblow.
 */
interface IStoredPosition extends IPosition
{
	/**
	 * Get position creation timestamp.
	 * @return int Unix timestamp.
	 */
	public function getCreatedAt(): int;

	/**
	 * Get position last update timestamp.
	 * @return int Unix timestamp.
	 */
	public function getUpdatedAt(): int;

	/**
	 * Get position finish timestamp.
	 * @return int Unix timestamp (0 if not finished).
	 */
	public function getFinishedAt(): int;

	/**
	 * Set current position volume.
	 * @param Money $volume New position volume.
	 * @return void
	 */
	public function setVolume(Money $volume): void;

	/**
	 * Get position status.
	 *
	 * Available only for stored positions, as their statuses are part
	 * of EZMoonblow built-in logic.
	 *
	 * @return PositionStatusEnum Position status (open, closed, pending).
	 */
	public function getStatus(): PositionStatusEnum;

	/**
	 * Check if position is currently open.
	 * @return bool True if position is open.
	 */
	public function isOpen(): bool;

	/**
	 * Get EZMoonblow’s internal position identifier.
	 * @return int Position ID in the local database.
	 */
	public function getPositionId(): int;

	/**
	 * Close the position with a market order.
	 * @return void
	 */
	public function close(): void;

	/**
	 * Buy additional volume to increase position size (DCA for long).
	 * @param Money $dcaAmount Amount to buy in quote currency.
	 * @return void
	 */
	public function buyAdditional(Money $dcaAmount): void;

	/**
	 * Sell additional volume to increase position size (DCA for short).
	 * @param Money $dcaAmount Amount to sell in quote currency.
	 * @return void
	 */
	public function sellAdditional(Money $dcaAmount): void;

	/**
	 * Set the expected profit percentage for take-profit calculation.
	 * @param float $expectedProfitPercent Expected profit in percent.
	 * @return void
	 */
	public function setExpectedProfitPercent(float $expectedProfitPercent): void;

	/**
	 * Get the expected profit percentage.
	 * @return float Expected profit in percent.
	 */
	public function getExpectedProfitPercent(): float;

	/**
	 * Update the take-profit order based on current position state.
	 * @param IMarket $market Market instance for exchange operations.
	 * @return void
	 */
	public function updateTakeProfit(IMarket $market): void;

	/**
	 * Save the position to the database.
	 * @return bool|int Row count or last insert ID on success, false on failure.
	 */
	public function save(): bool|int;

	/**
	 * Update position info from the exchange.
	 * @param IMarket $market Market instance for exchange operations.
	 * @return bool True on success.
	 */
	public function updateInfo(IMarket $market): bool;
}
