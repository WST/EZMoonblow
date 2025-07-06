<?php

namespace Izzy\Interfaces;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\System\Database\Database;

interface IMarket
{
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
	
	public function getTicker(): string;

	public function updateChart();

	public function openPosition(Money $volume, PositionDirectionEnum $direction, float $takeProfitPercent): IStoredPosition|false;

	public function getDatabase(): Database;

	public function hasOrder(string $orderIdOnExchange);

	public function drawChart();

	public function placeLimitOrder(Money $volume, Money $price, PositionDirectionEnum $direction, ?float $takeProfitPercent = null);

	public function openPositionByLimitOrderMap(array $orderMap, PositionDirectionEnum $directionEnum, float $takeProfitPercent);

	public function removeLimitOrders();

	public function setTakeProfit(Money $expectedTPPrice): bool;
}
