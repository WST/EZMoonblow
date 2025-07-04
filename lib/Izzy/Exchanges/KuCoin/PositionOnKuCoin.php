<?php

namespace Izzy\Exchanges\KuCoin;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPositionOnExchange;

/**
 * NOTE: KuCoin Futures API support.
 * This class implements position data from KuCoin Futures API.
 */
class PositionOnKuCoin implements IPositionOnExchange
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
		// KuCoin Futures API returns size as absolute value
		$size = abs($this->info['size'] ?? 0);
		return Money::from($size, $this->market->getPair()->getBaseCurrency());
	}

	public function getDirection(): PositionDirectionEnum {
		// KuCoin Futures API uses positive size for long, negative for short
		$size = $this->info['size'] ?? 0;
		return $size > 0 ? PositionDirectionEnum::LONG : PositionDirectionEnum::SHORT;
	}

	public function getAverageEntryPrice(): Money {
		// KuCoin Futures API returns average entry price
		$entryPrice = $this->info['avgEntryPrice'] ?? 0;
		return Money::from($entryPrice, $this->market->getPair()->getQuoteCurrency());
	}

	/*
	 * NOTE: There is no way to get the first "entry" price for the position.
	 * Instead, we are returning the average entry price here.
	 */
	public function getEntryPrice(): Money {
		return $this->getAverageEntryPrice();
	}

	public function getUnrealizedPnL(): Money {
		// KuCoin Futures API returns unrealized PnL
		$unrealizedPnl = $this->info['unrealisedPnl'] ?? 0;
		return Money::from($unrealizedPnl, $this->market->getPair()->getQuoteCurrency());
	}
	
	public function getCurrentPrice(): Money {
		// KuCoin Futures API returns mark price
		$markPrice = $this->info['markPrice'] ?? 0;
		return Money::from($markPrice, $this->market->getPair()->getQuoteCurrency());
	}

	public function getUnrealizedPnLPercent(): float {
		// Calculate PnL percentage based on entry price and current price
		$avgPrice = $this->getAverageEntryPrice()->getAmount();
		$markPrice = $this->getCurrentPrice()->getAmount();
		$direction = ($this->getDirection()->isLong()) ? 1 : -1;
		$pnlPercent = (($markPrice - $avgPrice) / $avgPrice) * 100 * $direction;
		return round($pnlPercent, 4);
	}
}
