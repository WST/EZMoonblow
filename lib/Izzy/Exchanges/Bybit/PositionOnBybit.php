<?php

namespace Izzy\Exchanges\Bybit;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Exchanges\AbstractPositionOnExchange;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;

/**
 * Represents a position on Bybit exchange.
 *
 * NOTE: Only USDT is supported as the quote currency.
 */
class PositionOnBybit extends AbstractPositionOnExchange
{
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
		parent::__construct($market);
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
		return Money::from($this->info['size'], $this->getBaseCurrency());
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
		return Money::from($this->info['avgPrice'], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * NOTE: There is no way to get the first "entry" price from Bybit.
	 * Returns the average entry price instead.
	 */
	public function getEntryPrice(): Money {
		return $this->getAverageEntryPrice();
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnL(): Money {
		return Money::from($this->info['unrealisedPnl'], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): Money {
		return Money::from($this->info['markPrice'], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getExchangePositionId(): string {
		return $this->info['positionIdx'] ?? 'unknown';
	}
}
