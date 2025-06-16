<?php

namespace Izzy;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;

class Market implements IMarket
{
	private string $ticker;
	
	private string $timeframe;
	
	private string $exchangeName;

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
		string $ticker,
		string $timeframe,
		string $exchangeName,
		MarketTypeEnum $marketType,
		array $candles
	) {
		$this->ticker = $ticker;
		$this->timeframe = $timeframe;
		$this->exchangeName = $exchangeName;
		$this->marketType = $marketType;
		$this->candles = $candles;

		// Устанавливаем текущий рынок для каждой свечи
		foreach ($this->candles as $candle) {
			$candle->setMarket($this);
		}
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
		return $this->ticker;
	}

	public function getTimeframe(): string {
		return $this->timeframe;
	}

	public function getExchangeName(): string {
		return $this->exchangeName;
	}

	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}

	public function getSymbol(): string {
		return $this->ticker;
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
}
