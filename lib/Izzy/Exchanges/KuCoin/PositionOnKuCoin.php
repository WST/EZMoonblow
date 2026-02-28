<?php

namespace Izzy\Exchanges\KuCoin;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Exchanges\AbstractPositionOnExchange;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;

/**
 * Represents a position on KuCoin exchange.
 *
 * KuCoin futures positions use contract counts for size,
 * with multiplier converting to base currency volume.
 *
 * NOTE: Only USDT is supported as the quote/settle currency.
 */
class PositionOnKuCoin extends AbstractPositionOnExchange
{
	/** @var array Raw position data from KuCoin API. */
	private array $info;

	/**
	 * @param IMarket $market Market the position belongs to.
	 * @param array $positionInfo Raw position data from KuCoin API.
	 */
	public function __construct(IMarket $market, array $positionInfo) {
		parent::__construct($market);
		$this->info = $positionInfo;
	}

	/**
	 * Static factory method.
	 *
	 * @param IMarket $market Market the position belongs to.
	 * @param array $positionInfo Raw position data from KuCoin API.
	 * @return static
	 */
	public static function create(IMarket $market, mixed $positionInfo): static {
		return new self($market, $positionInfo);
	}

	/**
	 * @inheritDoc
	 *
	 * KuCoin returns currentQty as contract count. Multiply by multiplier
	 * to get the base currency volume.
	 */
	public function getVolume(): Money {
		$contracts = abs((int) $this->info[KuCoinParam::CurrentQty]);
		$multiplier = abs((float) ($this->info[KuCoinParam::Multiplier] ?? 1));
		$volume = $contracts * $multiplier;
		return Money::from($volume, $this->getBaseCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * Positive currentQty = Long, negative = Short.
	 */
	public function getDirection(): PositionDirectionEnum {
		return ((int) $this->info[KuCoinParam::CurrentQty]) > 0
			? PositionDirectionEnum::LONG
			: PositionDirectionEnum::SHORT;
	}

	/**
	 * @inheritDoc
	 */
	public function getAverageEntryPrice(): Money {
		return Money::from($this->info[KuCoinParam::AvgEntryPrice], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * KuCoin does not expose the first entry price; returns average instead.
	 */
	public function getEntryPrice(): Money {
		return $this->getAverageEntryPrice();
	}

	/**
	 * @inheritDoc
	 */
	public function getUnrealizedPnL(): Money {
		return Money::from($this->info[KuCoinParam::UnrealisedPnl], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(): Money {
		return Money::from($this->info[KuCoinParam::MarkPrice], $this->getQuoteCurrency());
	}

	/**
	 * @inheritDoc
	 *
	 * KuCoin positions are identified by symbol.
	 */
	public function getExchangePositionId(): string {
		$symbol = $this->info[KuCoinParam::Symbol] ?? 'UNKNOWN';
		return "kucoin-{$symbol}";
	}
}
