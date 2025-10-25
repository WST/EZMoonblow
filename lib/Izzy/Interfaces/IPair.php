<?php

namespace Izzy\Interfaces;

interface IPair {
	/**
	 * Get the pair name in the format "BASE/QUOTE".
	 * @return string
	 */
	public function getTicker(): string;

	/**
	 * Get the ticker in the format required by the exchange driver.
	 * Example: "BTCUSDT" for Bybit spot, "BTC_USDT" for Gate spot.
	 *
	 * @param IExchangeDriver $exchangeDriver Exchange driver instance.
	 * @return string Formatted ticker for the exchange.
	 */
	public function getExchangeTicker(IExchangeDriver $exchangeDriver): string;

	/**
	 * Get the base currency of the pair.
	 * @return string
	 */
	public function getBaseCurrency(): string;

	/**
	 * Get the quote currency of the pair.
	 * @return string
	 */
	public function getQuoteCurrency(): string;

	public function __toString(): string;
}
