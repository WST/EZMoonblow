<?php

namespace Izzy\Exchanges\Gate;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Exchanges\AbstractPositionOnExchange;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;

/**
 * Represents a position on Gate.io exchange.
 *
 * Gate futures positions use contract counts (integers) for size,
 * with quanto_multiplier converting to base currency volume.
 *
 * NOTE: Only USDT is supported as the quote/settle currency.
 */
class PositionOnGate extends AbstractPositionOnExchange
{
	/** @var array Raw position data from Gate API. */
	private array $info;

	/**
	 * @param IMarket $market Market the position belongs to.
	 * @param array $positionInfo Raw position data from Gate API.
	 */
	public function __construct(IMarket $market, array $positionInfo) {
		parent::__construct($market);
		$this->info = $positionInfo;
	}

	/**
	 * Static factory method.
	 *
	 * @param IMarket $market Market the position belongs to.
	 * @param array $positionInfo Raw position data from Gate API.
	 * @return static
	 */
	public static function create(IMarket $market, mixed $positionInfo): static {
		return new self($market, $positionInfo);
	}

	/**
	 * @inheritDoc
	 *
	 * Gate returns size as contract count. Multiply by quanto_multiplier
	 * to get the base currency volume.
	 */
	public function getVolume(): Money {
		$contracts = abs((int) $this->info[GateParam::Size]);
		$multiplier = (float) ($this->info[GateParam::QuantoMultiplier] ?? 1);
		$volume = $contracts * $multiplier;
		return Money::from($volume, $this->getBaseCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * Positive size = Long, negative = Short.
	 */
	public function getDirection(): PositionDirectionEnum {
		return ((int) $this->info[GateParam::Size]) > 0
			? PositionDirectionEnum::LONG
			: PositionDirectionEnum::SHORT;
	}

	/**
	 * @inheritDoc
	 */
	public function getAverageEntryPrice(): Money {
		return Money::from($this->info[GateParam::EntryPrice], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * Gate does not expose the first entry price; returns average instead.
	 */
	public function getEntryPrice(): Money {
		return $this->getAverageEntryPrice();
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnL(): Money {
		return Money::from($this->info[GateParam::UnrealisedPnl], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): Money {
		return Money::from($this->info[GateParam::MarkPrice], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * Gate positions are identified by contract + mode.
	 */
	public function getExchangePositionId(): string {
		$contract = $this->info[GateParam::Contract] ?? 'UNKNOWN';
		$mode = $this->info[GateParam::Mode] ?? 'single';
		return "gate-{$contract}-{$mode}";
	}
}
