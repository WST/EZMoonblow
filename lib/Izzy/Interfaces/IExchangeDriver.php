<?php

namespace Izzy\Interfaces;

use Izzy\Financial\Money;

/**
 * Crypto exchange driver interface.
 */
interface IExchangeDriver
{
	/**
	 * Update the exchange state.
	 * @return int time to sleep before the next update in seconds.
	 */
	public function update(): int;

	/**
	 * Update the total balance from the exchange.
	 * @return void
	 */
	function updateBalance(): void;

	/**
	 * Connect to the exchange.
	 * @return bool
	 */
	public function connect(): bool;

	/**
	 * Disconnect from the exchange.
	 * This method should be called when the driver is no longer needed.
	 * @return void
	 */
	public function disconnect(): void;

	/**
	 * Get current market price for a trading pair.
	 *
	 * @param IMarket $market
	 * @return Money|null Current price or null if not available.
	 */
	public function getCurrentPrice(IMarket $market): ?Money;

	/**
	 * Open a long position.
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount to invest.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openLong(IMarket $market, Money $amount): bool;

	/**
	 * Open a short position (futures only).
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount to invest.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openShort(IMarket $market, Money $amount): bool;

	/**
	 * Place a market order to buy additional volume (DCA).
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount to buy.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool;

	/**
	 * Place a market order to sell additional volume.
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount to sell.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function sellAdditional(IMarket $market, Money $amount): bool;

	/**
	 * Get candles for the specified trading pair and timeframe.
	 *
	 * @param IPair $pair Trading pair.
	 * @param int $limit Number of candles (maximum 1000).
	 * @param int|null $startTime Start timestamp in milliseconds.
	 * @param int|null $endTime End timestamp in milliseconds.
	 * @return ICandle[] Array of candle objects.
	 */
	public function getCandles(
		IPair $pair,
		int $limit = 100,
		?int $startTime = null,
		?int $endTime = null
	): array;

	/**
	 * Get market instance for a trading pair.
	 *
	 * @param IPair $pair Trading pair.
	 * @return IMarket|null Market instance or null if not found.
	 */
	public function createMarket(IPair $pair): ?IMarket;
	
	public function pairToTicker(IPair $pair): string;

	public function getSpotBalanceByCurrency(string $coin): Money;

	public function getCurrentFuturesPosition(IMarket $market): IPosition|false;
}
