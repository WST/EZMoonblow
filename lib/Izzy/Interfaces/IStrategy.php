<?php

namespace Izzy\Interfaces;

use Izzy\Financial\StrategyValidationResult;

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
	 * @return IStoredPosition|false
	 */
	public function handleLong(IMarket $market): IStoredPosition|false;

	/**
	 * This method should manage opening a short position.
	 * @param IMarket $market
	 * @return IStoredPosition|false
	 */
	public function handleShort(IMarket $market): IStoredPosition|false;

	/**
	 * This method is called every minute when a position is open to enable the strategy to
	 * manipulate the currently opened position (i.e. increase/decrease volume, add TP/SL).
	 * @param IStoredPosition $position
	 * @return void
	 */
	public function updatePosition(IStoredPosition $position): void;

	/**
	 * Whether the strategy can hold Long and Short positions simultaneously on the same pair.
	 */
	public function isTwoWayMode(): bool;

	/**
	 * Returns the list of indicators used by this strategy.
	 * @return string[]
	 */
	public function useIndicators(): array;

	/**
	 * Validate that exchange settings are compatible with this strategy.
	 * Called before attempting to open positions and in the web UI.
	 *
	 * @param IMarket $market Market to validate against.
	 * @return StrategyValidationResult Validation result with errors and warnings.
	 */
	public function validateExchangeSettings(IMarket $market): StrategyValidationResult;
}
