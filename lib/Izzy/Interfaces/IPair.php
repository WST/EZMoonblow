<?php

namespace Izzy\Interfaces;

/**
 * Interface for a trading pair (e.g., BTC/USDT).
 */
interface IPair {
	/**
	 * Get the pair ticker in the format “BASE/QUOTE”.
	 * @return string Ticker (e.g., “BTC/USDT”).
	 */
	public function getTicker(): string;

	/**
	 * Get the ticker in the format required by the exchange driver.
	 *
	 * Different exchanges use different ticker formats.
	 * Example: “BTCUSDT” for Bybit futures, “BTC_USDT” for Gate spot.
	 *
	 * @param IExchangeDriver $exchangeDriver Exchange driver instance.
	 * @return string Formatted ticker for the exchange.
	 */
	public function getExchangeTicker(IExchangeDriver $exchangeDriver): string;

	/**
	 * Get the base currency of the pair.
	 * @return string Base currency symbol (e.g., “BTC”).
	 */
	public function getBaseCurrency(): string;

	/**
	 * Get the quote currency of the pair.
	 * @return string Quote currency symbol (e.g., “USDT”).
	 */
	public function getQuoteCurrency(): string;

	/**
	 * Get string representation of the pair.
	 * @return string Ticker string.
	 */
	public function __toString(): string;
}
