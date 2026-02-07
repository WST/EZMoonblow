<?php

namespace Izzy\Exchanges;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\StoredPosition;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPositionOnExchange;
use Izzy\Interfaces\IStoredPosition;

/**
 * Abstract base class for positions on exchanges.
 *
 * Provides common implementation for methods that retrieve data from the Market.
 * Exchange-specific position classes (PositionOnBybit, PositionOnGate, etc.)
 * should extend this class.
 */
abstract class PositionOnExchange implements IPositionOnExchange {
	/**
	 * Market this position belongs to.
	 * @var IMarket
	 */
	protected IMarket $market;

	/**
	 * Create a new position instance.
	 *
	 * @param IMarket $market Market the position belongs to.
	 */
	public function __construct(IMarket $market) {
		$this->market = $market;
	}

	/**
	 * Get the market this position belongs to.
	 * @return IMarket Market instance.
	 */
	public function getMarket(): IMarket {
		return $this->market;
	}

	/**
	 * @inheritDoc
	 */
	public function getExchangeName(): string {
		return $this->market->getExchange()->getName();
	}

	/**
	 * @inheritDoc
	 */
	public function getTicker(): string {
		return $this->market->getTicker();
	}

	/**
	 * @inheritDoc
	 */
	public function getBaseCurrency(): string {
		return $this->market->getPair()->getBaseCurrency();
	}

	/**
	 * @inheritDoc
	 */
	public function getQuoteCurrency(): string {
		return $this->market->getPair()->getQuoteCurrency();
	}

	/**
	 * @inheritDoc
	 */
	public function getMarketType(): MarketTypeEnum {
		return $this->market->getMarketType();
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnLPercent($precision = 4): float {
		$avgPrice = $this->getAverageEntryPrice();
		$currentPrice = $this->getCurrentPrice();
		$direction = ($this->getDirection()->isLong()) ? 1 : -1;
		$pnlPercent = $avgPrice->getPercentDifference($currentPrice) * $direction;
		return round($pnlPercent, $precision);
	}

	/**
	 * @inheritDoc
	 */
	public function store(): IStoredPosition {
		// Create StoredPosition from current position data.
		$storedPosition = StoredPosition::create(
			$this->market,
			$this->getVolume(),
			$this->getDirection(),
			$this->getEntryPrice(),
			$this->getCurrentPrice(),
			PositionStatusEnum::OPEN,
			$this->getExchangePositionId()
		);

		// Set additional data that’s available from Exchange-specific position.
		$storedPosition->setAverageEntryPrice($this->getAverageEntryPrice());

		// Save to the database.
		$storedPosition->save();

		return $storedPosition;
	}
}
