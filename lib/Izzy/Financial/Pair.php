<?php

namespace Izzy\Financial;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Interfaces\IPair;

class Pair implements IPair
{
	/**
	 * Ticker of the pair, e.g. BTC/USDT.
	 * @var string
	 */
	public string $ticker;

	/**
	 * Timeframe of the pair, e.g. 15m, 1h.
	 * @var TimeFrameEnum
	 */
	public TimeFrameEnum $timeframe;

	/**
	 * Exchange name, e.g. Bybit, Gate.
	 * @var string
	 */
	private string $exchangeName;

	/**
	 * Market type hint.
	 */
	public MarketTypeEnum $marketType;

	public function __construct(string $ticker, TimeFrameEnum $timeFrame, string $exchangeName, MarketTypeEnum $marketType) {
		$this->ticker = $ticker;
		$this->timeframe = $timeFrame;
		$this->exchangeName = $exchangeName;
		$this->marketType = $marketType;
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

	public function isInverseFutures(): bool {
		return false;
	}
	
	/**
	 * Example: BTC/USDT 15m spot Bybit.
	 * @return string
	 */
	public function getDescription(): string {
		return "{$this->ticker} {$this->timeframe->value} {$this->marketType->value} {$this->exchangeName}";
	}
	
	public function getExchangeName(): string {
		return $this->exchangeName;
	}

	public function setExchangeName(string $exchangeName): void {
		$this->exchangeName = $exchangeName;
	}

	public function getTicker(): string {
		return $this->ticker;
	}

	public function setTicker(string $ticker): void {
		$this->ticker = $ticker;
	}

	public function getTimeframe(): TimeFrameEnum {
		return $this->timeframe;
	}

	public function setTimeframe(TimeFrameEnum $timeframe): void {
		$this->timeframe = $timeframe;
	}

	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}

	public function setMarketType(MarketTypeEnum $marketType): void {
		$this->marketType = $marketType;
	}

	public function getChartFilename(): string {
		$basename = "{$this->ticker}_{$this->timeframe->value}_{$this->marketType->value}_{$this->exchangeName}.png";
		return IZZY_CHARTS . "/$basename";
	}
	
	public function getChartTitle(): string {
		return sprintf("%s %s %s %s %s",
			$this->getTicker(),
			$this->timeframe->value,
			$this->marketType->value,
			$this->exchangeName,
			date('Y-m-d H:i:s')
		);
	}
}
