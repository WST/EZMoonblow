<?php

namespace Izzy\Traits;

use Izzy\Financial\Money;

/**
 * Common functionality for all position classes.
 *
 * This trait provides shared implementation for methods that are identical
 * across StoredPosition and AbstractPositionOnExchange.
 *
 * Classes using this trait must implement:
 * - getAverageEntryPrice(): Money
 * - getCurrentPrice(): Money
 * - getDirection(): PositionDirectionEnum
 */
trait PositionTrait
{
	/**
	 * Get unrealized profit/loss in percent.
	 *
	 * Calculates PnL based on average entry price and current price,
	 * accounting for position direction (long/short).
	 *
	 * @return float Unrealized PnL percentage.
	 */
	public function getUnrealizedPnLPercent(int $precision = 4): float {
		$referencePrice = $this->getPriceForPnL();
		$currentPrice = $this->getCurrentPrice();
		$directionMultiplier = ($this->getDirection()->isLong()) ? 1 : -1;
		$pnlPercent = $referencePrice->getPercentDifference($currentPrice) * $directionMultiplier;
		return round($pnlPercent, $precision);
	}

	/**
	 * Get the reference price for PnL calculation.
	 * Uses average entry price if available, otherwise initial entry price.
	 *
	 * @return Money Reference price for PnL calculation.
	 */
	public function getPriceForPnL(): Money {
		$avgPrice = $this->getAverageEntryPrice();
		if ($avgPrice->getAmount() > 0) {
			return $avgPrice;
		}
		return $this->getEntryPrice();
	}
}
