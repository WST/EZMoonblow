<?php

namespace Izzy\Interfaces;

/**
 * Interface for a candlestick (OHLCV data).
 */
interface ICandle {
	/**
	 * Get the previous candle (or null for the first candle).
	 * @return ICandle|null
	 */
	public function previousCandle(): ?ICandle;

	/**
	 * Get the next candle (or null for the last candle).
	 * @return ICandle|null
	 */
	public function nextCandle(): ?ICandle;

	/**
	 * Get the candle open time (Unix timestamp in milliseconds).
	 * @return int
	 */
	public function getOpenTime(): int;

	/**
	 * Get the candle close time (Unix timestamp in milliseconds).
	 * @return int
	 */
	public function getCloseTime(): int;

	/**
	 * Get the candle open price.
	 * @return float
	 */
	public function getOpenPrice(): float;

	/**
	 * Get the candle close price.
	 * @return float
	 */
	public function getClosePrice(): float;

	/**
	 * Get the candle high price.
	 * @return float
	 */
	public function getHighPrice(): float;

	/**
	 * Get the candle low price.
	 * @return float
	 */
	public function getLowPrice(): float;

	/**
	 * Get the candle trading volume.
	 * @return float
	 */
	public function getVolume(): float;

	/**
	 * Get the candle body size (difference between open and close prices).
	 * @return float
	 */
	public function getSize(): float;

	/**
	 * Get the open interest at the candle open time (futures market only).
	 * @return float
	 */
	public function getOpenInterest(): float;

	/**
	 * Get the open interest change during this candle (futures market only).
	 * @return float
	 */
	public function getOpenInterestChange(): float;

	/**
	 * Get the fair value gap (FVG/imbalance) associated with this candle, if any.
	 * @return IFVG|null
	 */
	public function getFVG(): ?IFVG;

	/**
	 * Get the market this candle belongs to.
	 * @return IMarket
	 */
	public function getMarket(): IMarket;

	/**
	 * Set the market this candle belongs to.
	 * @param IMarket $market
	 * @return void
	 */
	public function setMarket(IMarket $market): void;

	/**
	 * Check if the candle is bullish (close > open).
	 * @return bool
	 */
	public function isBullish(): bool;

	/**
	 * Check if the candle is bearish (close < open).
	 * @return bool
	 */
	public function isBearish(): bool;
}
