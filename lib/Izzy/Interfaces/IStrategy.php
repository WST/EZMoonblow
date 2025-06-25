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

	public function handleLong();
	
	public function handleShort();
	
	public function updatePosition(): void;

	/**
	 * Returns the list of indicators used by this strategy.
	 * @return string[]
	 */
	public function useIndicators(): array;
}
