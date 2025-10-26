<?php

namespace Izzy\Interfaces;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\System\Database\Database;

interface IMarket {
	/**
	 * @return ICandle[]
	 */
	public function getCandles(): array;

	/**
	 * @return ICandle
	 */
	public function firstCandle(): ICandle;

	/**
	 * @return ICandle
	 */
	public function lastCandle(): ICandle;

	/**
	 * @return string
	 */
	public function getTicker(): string;

	/**
	 * @return void
	 */
	public function updateChart(): void;

	/**
	 * @param Money $volume
	 * @param PositionDirectionEnum $direction
	 * @param float $takeProfitPercent
	 * @return IStoredPosition|false
	 */
	public function openPosition(Money $volume, PositionDirectionEnum $direction, float $takeProfitPercent): IStoredPosition|false;

	/**
	 * @return Database
	 */
	public function getDatabase(): Database;

	/**
	 * @param string $orderIdOnExchange
	 * @return bool
	 */
	public function hasOrder(string $orderIdOnExchange): bool;

	/**
	 * @return string
	 */
	public function drawChart(): string;

	/**
	 * @param Money $volume
	 * @param Money $price
	 * @param PositionDirectionEnum $direction
	 * @param float|null $takeProfitPercent
	 * @return false|string
	 */
	public function placeLimitOrder(
		Money $volume,
		Money $price,
		PositionDirectionEnum $direction,
		?float $takeProfitPercent = null
	): string|false;

	/**
	 * @param array $orderMap
	 * @param PositionDirectionEnum $directionEnum
	 * @param float $takeProfitPercent
	 * @return IStoredPosition|false
	 */
	public function openPositionByLimitOrderMap(array $orderMap, PositionDirectionEnum $directionEnum, float $takeProfitPercent): IStoredPosition|false;

	/**
	 * @return bool
	 */
	public function removeLimitOrders(): bool;

	/**
	 * @param Money $expectedTPPrice
	 * @return bool
	 */
	public function setTakeProfit(Money $expectedTPPrice): bool;

	/**
	 * Get the trading pair for this market.
	 * @return IPair
	 */
	public function getPair(): IPair;

	/**
	 * Get the exchange driver for this market.
	 * @return IExchangeDriver
	 */
	public function getExchange(): IExchangeDriver;

	/**
	 * Get the market type for this market.
	 * @return MarketTypeEnum
	 */
	public function getMarketType(): MarketTypeEnum;
}
