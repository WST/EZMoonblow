<?php

namespace Izzy\Interfaces;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;

/**
 * Base interface for all positions.
 */
interface IPosition
{
	/**
	 * Get exchange name for this position.
	 * @return string Exchange name.
	 */
	public function getExchangeName(): string;

	/**
	 * Get ticker symbol for this position.
	 * @return string Ticker symbol.
	 */
	public function getTicker(): string;

	/**
	 * Get base currency for this position.
	 * @return string Base currency code.
	 */
	public function getBaseCurrency(): string;

	/**
	 * Get quote currency for this position.
	 * @return string Quote currency code.
	 */
	public function getQuoteCurrency(): string;

	/**
	 * Get the market type for this position.
	 * @return MarketTypeEnum Market type (spot or futures).
	 */
	public function getMarketType(): MarketTypeEnum;

	/**
	 * Get current position volume.
	 * @return Money Position volume in base currency.
	 */
	public function getVolume(): Money;

	/**
	 * Get position direction.
	 * @return PositionDirectionEnum Position direction (long or short).
	 */
	public function getDirection(): PositionDirectionEnum;

	/**
	 * Get entry price of the position.
	 * @return Money Initial entry price.
	 */
	public function getEntryPrice(): Money;

	/**
	 * Get the average entry price of the position.
	 * @return Money Average entry price (accounting for DCA).
	 */
	public function getAverageEntryPrice(): Money;

	/**
	 * Get current price of the base currency in quote currency.
	 * @return Money Current market price.
	 */
	public function getCurrentPrice(): Money;

	/**
	 * Get unrealized profit/loss.
	 * @return Money Unrealized PnL in quote currency.
	 */
	public function getUnrealizedPnL(): Money;

	/**
	 * Get unrealized profit/loss in percent of the position size (not the margin).
	 * @param int $precision Precision
	 * @return float Unrealized PnL percentage.
	 */
	public function getUnrealizedPnLPercent(int $precision = 4): float;
}
