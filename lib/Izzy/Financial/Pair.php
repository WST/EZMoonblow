<?php

namespace Izzy\Financial;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Interfaces\IPair;

class Pair implements IPair
{
	private string $ticker;
	
	private TimeFrameEnum $timeframe;
	
	private string $exchangeName;

	/**
	 * Market type hint.
	 */
	private MarketTypeEnum $marketType;

	public function __construct(string $ticker, TimeFrameEnum $timeFrame, string $exchangeName, MarketTypeEnum $marketType) {
		$this->ticker = $ticker;
		$this->timeframe = $timeFrame;
		$this->exchangeName = $exchangeName;
		$this->marketType = $marketType;
	}
	
	public function getTicker(): string {
		return $this->ticker;
	}
	
	public function getTimeframe(): TimeFrameEnum {
		return $this->timeframe;
	}
	
	public function getExchangeName(): string {
		return $this->exchangeName;
	}
	
	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}
	
	public function isTradingEnabled(): bool {
		return false;
	}
	
	public function isMonitoringEnabled(): bool {
		return false;
	}

	public function isSpot(): bool {
		return $this->marketType->isSpot();
	}

	public function isFutures(): bool {
		return $this->marketType->isFutures();
	}
}
