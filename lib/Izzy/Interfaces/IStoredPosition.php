<?php

namespace Izzy\Interfaces;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\Money;

/**
 * Represents currently open position saved into the local database.
 */
interface IStoredPosition extends IPosition {
	/**
	 * Set current position volume.
	 * @param Money $volume
	 * @return void
	 */
	public function setVolume(Money $volume): void;


	/**
	 * Get position status: 'open', 'closed', 'pending'.
	 * Available only for stored positions, as their statuses are part of EZMoonblow built-in logic.
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

	public function sellAdditional(Money $dcaAmount);

	public function setExpectedProfitPercent(float $expectedProfitPercent): void;

	public function getExpectedProfitPercent(): float;

	public function updateTakeProfit(): void;

	public function save(): bool|int;

	public function updateInfo(): bool;
}
