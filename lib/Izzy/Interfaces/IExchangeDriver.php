<?php

namespace Izzy\Interfaces;

use Izzy\Financial\Money;

/**
 * Интерфейс криптобиржи
 */
interface IExchangeDriver
{
	/**
	 * Обновить информацию с биржи / на бирже
	 * @return int на сколько секунд заснуть после обновления
	 */
	public function update(): int;

	// Установить соединение с биржей
	public function connect(): bool;

	// Отсоединиться от биржи
	public function disconnect(): void;

	/**
	 * Get current position for a trading pair.
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @return IPosition|null Current position or null if no position.
	 */
	public function getCurrentPosition(string $ticker): ?IPosition;

	/**
	 * Get current market price for a trading pair.
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @return float|null Current price or null if not available.
	 */
	public function getCurrentPrice(string $ticker): ?float;

	/**
	 * Open a long position.
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openLong(string $ticker, Money $amount, ?float $price = null): bool;

	/**
	 * Open a short position (futures only).
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openShort(string $ticker, Money $amount, ?float $price = null): bool;

	/**
	 * Close an existing position.
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function closePosition(string $ticker, ?float $price = null): bool;

	/**
	 * Place a market order to buy additional volume (DCA).
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @param Money $amount Amount to buy.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function buyAdditional(string $ticker, Money $amount): bool;

	/**
	 * Place a market order to sell additional volume.
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @param Money $amount Amount to sell.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function sellAdditional(string $ticker, Money $amount): bool;

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
}
