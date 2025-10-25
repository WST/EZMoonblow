<?php

namespace Izzy\Interfaces;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;

/**
 * Crypto exchange driver interface.
 */
interface IExchangeDriver {
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
	 * Open a Long or Short position by a single limit or market order.
	 *
	 * @param IMarket $market
	 * @param PositionDirectionEnum $direction
	 * @param Money $amount Amount to invest.
	 * @param Money|null $price
	 * @param float|null $takeProfitPercent
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openPosition(IMarket $market, PositionDirectionEnum $direction, Money $amount, ?Money $price = null, ?float $takeProfitPercent = null): bool;

	/**
	 * Buy additional volume (market).
	 * @param IMarket $market
	 * @param Money $amount Amount to buy.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool;

	/**
	 * Sell additional volume (market).
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

	public function getCurrentFuturesPosition(IMarket $market): IPositionOnExchange|false;

	/**
	 * Place a limit order.
	 * @param IMarket $market Active Market to place the order into.
	 * @param Money $amount Amount in the base currency.
	 * @param Money $price Price in the quote currency.
	 * @param PositionDirectionEnum $direction
	 * @param float|null $takeProfitPercent Take Profit distance from the entry in %.
	 * @return string|false Order Id on the Exchange on success, false on failure.
	 */
	public function placeLimitOrder(
		IMarket $market,
		Money $amount,
		Money $price,
		PositionDirectionEnum $direction,
		?float $takeProfitPercent = null
	): string|false;

	/**
	 * Remove all limit orders from current Market (i.e. remove DCAs after a successful trade).
	 * @param IMarket $market
	 * @return bool
	 */
	public function removeLimitOrders(IMarket $market): bool;

	public function setTakeProfit(IMarket $market, Money $expectedPrice): bool;
}
