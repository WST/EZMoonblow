<?php

namespace Izzy\Interfaces;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Financial\Order;
use Izzy\System\Database\Database;
use Izzy\System\Logger;
use Izzy\Configuration\ExchangeConfiguration;

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
	public function updateBalance(): void;

	/**
	 * Connect to the exchange API.
	 * @return bool True if connection successful, false otherwise.
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

	/**
	 * Convert a trading pair to exchange-specific ticker format.
	 *
	 * @param IPair $pair Trading pair.
	 * @return string Exchange-specific ticker (e.g., "BTCUSDT" for Bybit).
	 */
	public function pairToTicker(IPair $pair): string;

	/**
	 * Get spot wallet balance for a specific currency.
	 *
	 * @param string $coin Currency symbol (e.g., "BTC", "USDT").
	 * @return Money Balance amount.
	 */
	public function getSpotBalanceByCurrency(string $coin): Money;

	/**
	 * Get current futures position for a market.
	 *
	 * @param IMarket $market Market to check.
	 * @return IPositionOnExchange|false Position data or false if no position exists.
	 */
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

	/**
	 * Set or update take profit order for a position.
	 *
	 * @param IMarket $market Market with the position.
	 * @param Money $expectedPrice Target take profit price.
	 * @return bool True on success, false on failure.
	 */
	public function setTakeProfit(IMarket $market, Money $expectedPrice): bool;

	/**
	 * Get the database instance used by this exchange driver.
	 * @return Database Database instance.
	 */
	public function getDatabase(): Database;

	/**
	 * Get the logger instance used by this exchange driver.
	 * @return Logger Logger instance.
	 */
	public function getLogger(): Logger;

	/**
	 * Get the exchange name (e.g., "Bybit", "Gate").
	 * @return string Exchange name.
	 */
	public function getName(): string;

	/**
	 * Get the exchange configuration.
	 * @return ExchangeConfiguration Exchange configuration instance.
	 */
	public function getExchangeConfiguration(): ExchangeConfiguration;

	/**
	 * Check if an order is still active on the exchange.
	 *
	 * @param IMarket $market Market the order belongs to.
	 * @param string $orderIdOnExchange Order ID on the exchange.
	 * @return bool True if order is active, false otherwise.
	 */
	public function hasActiveOrder(IMarket $market, string $orderIdOnExchange): bool;

	/**
	 * Get order information by its exchange ID.
	 *
	 * @param IMarket $market Market the order belongs to.
	 * @param string $orderIdOnExchange Order ID on the exchange.
	 * @return Order|false Order object or false if not found.
	 */
	public function getOrderById(IMarket $market, string $orderIdOnExchange): Order|false;

	/**
	 * Get the minimum quantity step for an instrument.
	 *
	 * Used for rounding order quantities to exchange requirements.
	 * Each exchange has specific precision requirements for order sizes.
	 *
	 * @param IMarket $market Market to get qty step for.
	 * @return string Quantity step (e.g., "0.001" for BTC).
	 */
	public function getQtyStep(IMarket $market): string;

	/**
	 * Get the minimum price tick size for an instrument.
	 *
	 * Used for rounding prices to exchange requirements.
	 * Each exchange has specific precision requirements for prices.
	 *
	 * @param IMarket $market Market to get tick size for.
	 * @return string Tick size (e.g., "0.01" for USDT pairs).
	 */
	public function getTickSize(IMarket $market): string;
}
