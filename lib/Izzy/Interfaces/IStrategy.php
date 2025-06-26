<?php

namespace Izzy\Interfaces;

interface IStrategy
{
	/**
	 * This method should return true to indicate that the strategy “wants” to go long
	 * or buy the resource on the spot.
	 * @return bool
	 */ 
	public function shouldLong(): bool;

	/**
	 * This method should return true to indicate that the strategy “wants” to go short.
	 * Never gets called on spot markets.
	 * @return bool
	 */
	public function shouldShort(): bool;

	/**
	 * This method should manage opening a long position.
	 * @param IMarket $market
	 * @return IPosition|false
	 */
	public function handleLong(IMarket $market): IPosition|false;

	/**
	 * This method should manage opening a short position.
	 * @param IMarket $market
	 * @return IPosition|false
	 */
	public function handleShort(IMarket $market): IPosition|false;

	/**
	 * This method is called every minute when a position is open to enable the strategy to
	 * manipulate the currently opened position (i.e. increase/decrease volume, add TP/SL).
	 * @param IPosition $position
	 * @return void
	 */
	public function updatePosition(IPosition $position): void;

	/**
	 * Returns the list of indicators used by this strategy.
	 * @return string[]
	 */
	public function useIndicators(): array;
}
