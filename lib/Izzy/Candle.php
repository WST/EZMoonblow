<?php

namespace Izzy;

use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IFVG;

class Candle implements ICandle
{
	protected int $timestamp;
	protected float $open;
	protected float $high;
	protected float $low;
	protected float $close;
	protected float $volume;
	protected ?Market $market;
	
	protected ?ICandle $previousCandle;
	protected ?ICandle $nextCandle;

	public function __construct(int $timestamp, float $open, float $high, float $low, float $close, float $volume, Market $market = null)
	{
		$this->timestamp = $timestamp;
		$this->open = $open;
		$this->high = $high;
		$this->low = $low;
		$this->close = $close;
		$this->volume = $volume;
		$this->market = $market;
	}

	public function previousCandle(): ?ICandle {
		return $this->previousCandle;
	}

	public function nextCandle(): ?ICandle {
		return $this->nextCandle;
	}

	public function getOpenTime(): int {
		return $this->timestamp;
	}

	public function getCloseTime(): int {
		// Получаем таймфрейм из рынка
		$timeframe = $this->market ? $this->market->getTimeframe() : '15';
		$minutes = (int)$timeframe;
		return $this->timestamp + $minutes * 60;
	}

	public function getOpenPrice(): float {
		return $this->open;
	}

	public function getHighPrice(): float {
		return $this->high;
	}

	public function getLowPrice(): float {
		return $this->low;
	}

	public function getClosePrice(): float {
		return $this->close;
	}

	public function getVolume(): float {
		return $this->volume;
	}

	public function getSize(): float {
		return abs($this->close - $this->open);
	}

	public function getOpenInterest(): float {
		return 0.0; // Для спотового рынка всегда 0
	}

	public function getOpenInterestChange(): float {
		return 0.0; // Для спотового рынка всегда 0
	}

	public function getFVG(): ?IFVG {
		return null; // Пока не реализовано
	}

	public function getMarket(): IMarket {
		return $this->market;
	}

	public function isBullish(): bool {
		return $this->close > $this->open;
	}

	public function isBearish(): bool {
		return $this->isBullish();
	}

	public function getBodyHeight(): float {
		return abs($this->close - $this->open);
	}

	public function getUpperWickHeight(): float {
		return $this->high - max($this->open, $this->close);
	}

	public function getLowerWickHeight(): float {
		return min($this->open, $this->close) - $this->low;
	}

	public function setPreviousCandle(?ICandle $candle): void {
		$this->previousCandle = $candle;
	}

	public function setNextCandle(?ICandle $candle): void {
		$this->nextCandle = $candle;
	}

	public function setMarket(IMarket $market): void {
		$this->market = $market;
	}
}
