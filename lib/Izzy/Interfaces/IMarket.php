<?php

namespace Izzy\Interfaces;

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

	public function openLongPosition(Money $volume): IPosition|false;

	public function openShortPosition(Money $volume): IPosition|false;

	public function getDatabase(): Database;

	public function hasOrder(string $orderIdOnExchange);

	public function drawChart();

	public function placeLimitOrder(Money $volume, Money $price, string $side);

	public function openLongByLimitOrderMap(array $orderMap);

	public function removeLimitOrders();

	public function getQtyStep();
}
