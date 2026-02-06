<?php

namespace Izzy\Exchanges\Bybit;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\Money;
use Izzy\Financial\StoredPosition;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPositionOnExchange;
use Izzy\Interfaces\IStoredPosition;

/**
 * Represents a position on Bybit exchange.
 *
 * NOTE: Only USDT is supported as the quote currency.
 */
class PositionOnBybit implements IPositionOnExchange {
	/**
	 * Market this position belongs to.
	 * @var IMarket
	 */
	private IMarket $market;

	/**
	 * Raw position info from Bybit API.
	 * @var array
	 */
	private array $info = [];

	/**
	 * Create a new Bybit position instance.
	 *
	 * @param IMarket $market Market the position belongs to.
	 * @param array $positionInfo Raw position data from Bybit API.
	 */
	public function __construct(IMarket $market, array $positionInfo) {
		$this->market = $market;
		$this->info = $positionInfo;
	}

	/**
	 * Static factory method to create a position instance.
	 *
	 * @param IMarket $market Market the position belongs to.
	 * @param array $positionInfo Raw position data from Bybit API.
	 * @return static New position instance.
	 */
	public static function create(IMarket $market, mixed $positionInfo): static {
		return new self($market, $positionInfo);
	}

	/**
	 * @inheritDoc
	 */
	public function getVolume(): Money {
		return Money::from($this->info['size'], $this->market->getPair()->getBaseCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getDirection(): PositionDirectionEnum {
		return match ($this->info['side']) {
			'Buy' => PositionDirectionEnum::LONG,
			'Sell' => PositionDirectionEnum::SHORT,
		};
	}

	/**
	 * @inheritDoc
	 */
	public function getAverageEntryPrice(): Money {
		return Money::from($this->info['avgPrice'], $this->market->getPair()->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * NOTE: There is no way to get the first “entry” price from Bybit.
	 * Returns the average entry price instead.
	 */
	public function getEntryPrice(): Money {
		return $this->getAverageEntryPrice();
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnL(): Money {
		return Money::from($this->info['unrealisedPnl'], $this->market->getPair()->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): Money {
		return Money::from($this->info['markPrice'], $this->market->getPair()->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnLPercent(): float {
		$avgPrice = $this->getAverageEntryPrice();
		$markPrice = $this->getCurrentPrice();
		$direction = ($this->getDirection()->isLong()) ? 1 : -1;
		$pnlPercent = $avgPrice->getPercentDifference($markPrice) * $direction;
		return round($pnlPercent, 4);
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
			$this->info['positionIdx'] ?? 'unknown'
		);

		// Set additional data that’s available from Bybit position.
		$storedPosition->setAverageEntryPrice($this->getAverageEntryPrice());
		
		// Save to database.
		$storedPosition->save();

		return $storedPosition;
	}
}
