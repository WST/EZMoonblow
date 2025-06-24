<?php

namespace Izzy\Financial;

use Izzy\Chart\Chart;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;
use Izzy\IPosition;

class Market implements IMarket
{
	/**
	 * Active pair.
	 */
	private Pair $pair;

	/**
	 * The relevant exchange driver.
	 */
	private IExchangeDriver $exchange;

	/**
	 * Market type: spot or futures.
	 */
	private MarketTypeEnum $marketType;
	
	/**
	 * Set of candles.
	 * @var ICandle[]
	 */
	private array $candles;

	public function __construct(
		Pair $pair,
		IExchangeDriver $exchange
	) {
		$this->exchange = $exchange;
		$this->marketType = $pair->getMarketType();
	}

	/**
	 * @return ICandle[]
	 */
	public function getCandles(): array {
		return $this->candles;
	}

	public function firstCandle(): ICandle {
		return reset($this->candles);
	}

	public function lastCandle(): ICandle {
		return end($this->candles);
	}

	public function getTicker(): string {
		return $this->pair->getTicker();
	}

	public function getTimeframe(): TimeFrameEnum {
		return $this->pair->getTimeframe();
	}

	public function getExchange(): IExchangeDriver {
		return $this->exchange;
	}

	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}

	public function getMinPrice(): float {
		if (empty($this->candles)) {
			return 0.0;
		}
		return array_reduce($this->candles, function ($min, $candle) {
			return min($min, $candle->getLowPrice());
		}, PHP_FLOAT_MAX);
	}

	public function getMaxPrice(): float {
		if (empty($this->candles)) {
			return 0.0;
		}
		return array_reduce($this->candles, function ($max, $candle) {
			return max($max, $candle->getHighPrice());
		}, PHP_FLOAT_MIN);
	}

	public function getPriceRange(): float {
		return $this->getMaxPrice() - $this->getMinPrice();
	}

	public function setStrategy(IStrategy $strategy): void {
		// TODO: Implement setStrategy() method.
	}

	public function isSpot(): bool {
		return $this->marketType->isSpot();
	}

	public function isFutures(): bool {
		return $this->marketType->isFutures();
	}

	/**
	 * @inheritDoc
	 * @return bool
	 */
	public function isLowPrice(): bool {
		// TODO: Implement isLowPrice() method.
	}

	/**
	 * @inheritDoc
	 * @return bool
	 */
	public function isHighPrice(): bool {
		// TODO: Implement isHighPrice() method.
	}

	public function drawChart(TimeFrameEnum $timeframe): Chart {
		$chart = new Chart($this, $timeframe);
		$chart->draw();
		return $chart;
	}

	public function setCandles(array $candlesData) {
		$this->candles = $candlesData;

		// Устанавливаем текущий рынок для каждой свечи
		foreach ($this->candles as $candle) {
			$candle->setMarket($this);
		}
	}
	
	public function getPosition(): ?IPosition {
		
	}
}
