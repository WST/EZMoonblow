<?php

namespace Izzy\Enums;

/**
 * Market type. Only “spot” and “futures” will probably be ever supported.
 */
enum MarketTypeEnum: string {
	case SPOT = 'spot';
	case FUTURES = 'futures';

	/**
	 * Indicates if the market is a futures market (positions, leverage).
	 * @return bool
	 */
	public function isFutures(): bool {
		return $this === self::FUTURES;
	}

	/**
	 * Indicates if the market is a spot market (buy/sell).
	 * @return bool
	 */
	public function isSpot(): bool {
		return $this === self::SPOT;
	}

	/**
	 * Indicates if the market is an inverse futures market.
	 * @return bool
	 */
	public function isInverseFutures(): false {
		return false; // Currently, no support for inverse futures markets.
	}

	public function toString(): string {
		return $this->value;
	}
}
