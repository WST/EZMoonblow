<?php

namespace Izzy\Exchanges\Backtest;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Exchanges\AbstractPositionOnExchange;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;

/**
 * Wraps an existing BacktestStoredPosition as an IPositionOnExchange.
 *
 * Used by BacktestExchange::getCurrentFuturesPosition() to provide a fallback
 * when getStoredPosition() fails to find the position. The store() method
 * returns the existing stored position without creating a duplicate.
 */
class BacktestPositionOnExchange extends AbstractPositionOnExchange
{
	private BacktestStoredPosition $storedPosition;

	public function __construct(IMarket $market, BacktestStoredPosition $storedPosition) {
		parent::__construct($market);
		$this->storedPosition = $storedPosition;
	}

	public function getVolume(): Money {
		return $this->storedPosition->getVolume();
	}

	public function getDirection(): PositionDirectionEnum {
		return $this->storedPosition->getDirection();
	}

	public function getEntryPrice(): Money {
		return $this->storedPosition->getEntryPrice();
	}

	public function getCurrentPrice(): Money {
		// In backtest mode, return the actual simulated tick price from the exchange
		// rather than the stale price stored in the database. This is critical
		// because StoredPosition::updateInfo() overwrites the position's current
		// price with the value returned here; using the DB price would undo the
		// fresh tick price set earlier in updateInfo(), preventing Breakeven Lock
		// and other price-dependent logic from seeing the real current price.
		$exchange = $this->market->getExchange();
		$livePrice = $exchange->getCurrentPrice($this->market);
		return $livePrice ?? $this->storedPosition->getCurrentPrice();
	}

	public function getAverageEntryPrice(): Money {
		return $this->storedPosition->getAverageEntryPrice();
	}

	public function getExchangePositionId(): string {
		return $this->storedPosition->getIdOnExchange();
	}

	public function getUnrealizedPnL(): Money {
		return $this->storedPosition->getUnrealizedPnL();
	}

	/**
	 * Return the existing stored position without creating a duplicate record.
	 */
	public function store(): IStoredPosition {
		return $this->storedPosition;
	}
}
