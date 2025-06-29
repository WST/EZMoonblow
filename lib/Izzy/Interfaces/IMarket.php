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
}
