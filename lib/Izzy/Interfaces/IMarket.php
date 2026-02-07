<?php

namespace Izzy\Interfaces;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Financial\TradingContext;
use Izzy\Strategies\DCAOrderGrid;
use Izzy\System\Database\Database;

/**
 * Interface for market representation.
 *
 * A market represents a specific trading pair on a specific exchange
 * with associated candle data, indicators, and trading capabilities.
 */
interface IMarket {
	/**
	 * Get all candles for this market.
	 *
	 * @return ICandle[] Array of candle objects.
	 */
	public function getCandles(): array;

	/**
	 * Get the first (oldest) candle.
	 *
	 * @return ICandle First candle in the series.
	 */
	public function firstCandle(): ICandle;

	/**
	 * Get the last (most recent) candle.
	 *
	 * @return ICandle Last candle in the series.
	 */
	public function lastCandle(): ICandle;

	/**
	 * Get the trading pair ticker (e.g., "BTC/USDT").
	 *
	 * @return string Trading pair ticker.
	 */
	public function getTicker(): string;

	/**
	 * Schedule a task for updating the candlestick chart.
	 * Creates a queue task that will be processed by the Analyzer.
	 *
	 * @return void
	 */
	public function updateChart(): void;

	/**
	 * Open a new position with market order.
	 *
	 * @param Money $volume Position volume in quote currency.
	 * @param PositionDirectionEnum $direction Position direction (LONG or SHORT).
	 * @param float $takeProfitPercent Take profit percentage from entry price.
	 * @return IStoredPosition|false Created position or false on failure.
	 */
	public function openPosition(Money $volume, PositionDirectionEnum $direction, float $takeProfitPercent): IStoredPosition|false;

	/**
	 * Get the database instance associated with this market.
	 *
	 * @return Database Database instance.
	 */
	public function getDatabase(): Database;

	/**
	 * Check if an order exists on the exchange.
	 *
	 * @param string $orderIdOnExchange Order ID as returned by the exchange.
	 * @return bool True if order exists and is active, false otherwise.
	 */
	public function hasOrder(string $orderIdOnExchange): bool;

	/**
	 * Draw the candlestick chart with indicators.
	 * Initializes indicators, calculates values, and saves chart to file.
	 *
	 * @return string Path to the generated chart image file.
	 */
	public function drawChart(): string;

	/**
	 * Place a limit order on the exchange.
	 *
	 * @param Money $volume Order volume in base currency.
	 * @param Money $price Limit price.
	 * @param PositionDirectionEnum $direction Order direction (LONG or SHORT).
	 * @param float|null $takeProfitPercent Optional take profit percentage.
	 * @return string|false Order ID on success, false on failure.
	 */
	public function placeLimitOrder(
		Money $volume,
		Money $price,
		PositionDirectionEnum $direction,
		?float $takeProfitPercent = null
	): string|false;

	/**
	 * Open position using a DCA order grid.
	 * Places entry order at current price and averaging orders at specified offsets.
	 *
	 * @param DCAOrderGrid $grid DCA order grid with levels configuration.
	 * @return IStoredPosition|false Created position or false on failure.
	 */
	public function openPositionByDCAGrid(DCAOrderGrid $grid): IStoredPosition|false;

	/**
	 * Remove all pending limit orders for this market.
	 *
	 * @return bool True if successful, false otherwise.
	 */
	public function removeLimitOrders(): bool;

	/**
	 * Set or update take profit price for the current position.
	 *
	 * @param Money $expectedTPPrice Target take profit price.
	 * @return bool True if successful, false otherwise.
	 */
	public function setTakeProfit(Money $expectedTPPrice): bool;

	/**
	 * Get the trading pair for this market.
	 *
	 * @return IPair Trading pair instance.
	 */
	public function getPair(): IPair;

	/**
	 * Get the exchange driver for this market.
	 *
	 * @return IExchangeDriver Exchange driver instance.
	 */
	public function getExchange(): IExchangeDriver;

	/**
	 * Get the market type (spot or futures).
	 *
	 * @return MarketTypeEnum Market type enum.
	 */
	public function getMarketType(): MarketTypeEnum;

	/**
	 * Get the current trading context for volume calculations.
	 * Provides runtime data (balance, margin, price) needed for
	 * resolving dynamic volume modes like percentage of balance.
	 *
	 * @return TradingContext Trading context with current market data.
	 */
	public function getTradingContext(): TradingContext;

	/**
	 * Get the current active position for this market.
	 * For spot markets, returns stored position from database.
	 * For futures, checks both database and exchange for open positions.
	 *
	 * @return IStoredPosition|false Current position or false if none exists.
	 */
	public function getCurrentPosition(): IStoredPosition|false;
}
