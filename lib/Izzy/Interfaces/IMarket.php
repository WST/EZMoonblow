<?php

namespace Izzy\Interfaces;

interface IMarket extends IHasMarketType
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

	/**
	 * Установить для торговли на данном рынке стратегию $strategy
	 * @param IStrategy $strategy
	 * @return void
	 */
	public function setStrategy(IStrategy $strategy): void;
	
	public function getTicker(): string;

	public function updateChart();
}
