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
	 * Get current position for a trading pair.
	 * 
	 * @param IPair $pair Trading pair.
	 * @return IPosition|null Current position or null if no position.
	 */
	public function getCurrentPosition(IPair $pair): ?IPosition;

	/**
	 * Get current market price for a trading pair.
	 * 
	 * @param IPair $pair Trading pair.
	 * @return float|null Current price or null if not available.
	 */
	public function getCurrentPrice(IPair $pair): ?float;

	/**
	 * Open a long position.
	 * 
	 * @param IPair $pair Trading pair.
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openLong(IPair $pair, Money $amount, ?float $price = null): bool;

	/**
	 * Open a short position (futures only).
	 * 
	 * @param IPair $pair Trading pair.
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openShort(IPair $pair, Money $amount, ?float $price = null): bool;

	/**
	 * Close an existing position.
	 * 
	 * @param IPair $pair Trading pair.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function closePosition(IPair $pair, ?float $price = null): bool;

	/**
	 * Place a market order to buy additional volume (DCA).
	 * 
	 * @param IPair $pair Trading pair.
	 * @param Money $amount Amount to buy.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function buyAdditional(IPair $pair, Money $amount): bool;

	/**
	 * Place a market order to sell additional volume.
	 * 
	 * @param IPair $pair Trading pair.
	 * @param Money $amount Amount to sell.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function sellAdditional(IPair $pair, Money $amount): bool;

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
	public function getMarket(IPair $pair): ?IMarket;
	
	public function pairToTicker(IPair $pair): string;
}
