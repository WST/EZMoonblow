<?php

namespace Izzy\Exchanges\Gate;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPositionOnExchange;

/**
 * NOTE: only USDT supported as the quote currency.
 */
class PositionOnGate implements IPositionOnExchange
{
	private IMarket $market;
	
	private array $info = [];
	
	public function __construct(IMarket $market, array $positionInfo) {
		$this->market = $market;
		$this->info = $positionInfo;
	}

	public static function create(IMarket $market, mixed $positionInfo): static {
		return new self($market, $positionInfo);
	}

	public function getVolume(): Money {
		// Gate API returns size as integer, we need to convert to base currency amount
		$size = abs($this->info['size']);
		return Money::from($size, $this->market->getPair()->getBaseCurrency());
	}

	public function getDirection(): PositionDirectionEnum {
		// Gate API uses positive size for long, negative for short
		return $this->info['size'] > 0 ? PositionDirectionEnum::LONG : PositionDirectionEnum::SHORT;
	}

	public function getAverageEntryPrice(): Money {
		return Money::from($this->info['entry_price'], $this->market->getPair()->getQuoteCurrency());
	}

	/*
	 * NOTE: There is no way to get the first "entry" price for the position.
	 * Instead, we are returning the average entry price here.
	 */
	public function getEntryPrice(): Money {
		return $this->getAverageEntryPrice();
	}

	public function getUnrealizedPnL(): Money {
		return Money::from($this->info['unrealised_pnl'], $this->market->getPair()->getQuoteCurrency());
	}
	
	public function getCurrentPrice(): Money {
		return Money::from($this->info['mark_price'], $this->market->getPair()->getQuoteCurrency());
	}

	public function getUnrealizedPnLPercent(): float {
		$avgPrice = $this->getAverageEntryPrice()->getAmount();
		$markPrice = $this->getCurrentPrice()->getAmount();
		$direction = ($this->getDirection()->isLong()) ? 1 : -1;
		$pnlPercent = (($markPrice - $avgPrice) / $avgPrice) * 100 * $direction;
		return round($pnlPercent, 4);
	}
}