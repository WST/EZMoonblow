<?php

namespace Izzy\Financial;

use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IFVG;

/**
 * Represents a candlestick (OHLCV data point).
 */
class Candle implements ICandle
{
	protected int $timestamp;
	protected float $open;
	protected float $high;
	protected float $low;
	protected float $close;
	protected float $volume;
	protected ?Market $market;

	protected ?ICandle $previousCandle = null;
	protected ?ICandle $nextCandle = null;

	/**
	 * Create a new candle.
	 *
	 * @param int $timestamp Open time (Unix timestamp in seconds).
	 * @param float $open Open price.
	 * @param float $high High price.
	 * @param float $low Low price.
	 * @param float $close Close price.
	 * @param float $volume Trading volume.
	 * @param Market|null $market Market this candle belongs to.
	 */
	public function __construct(int $timestamp, float $open, float $high, float $low, float $close, float $volume, ?Market $market = null) {
		$this->timestamp = $timestamp;
		$this->open = $open;
		$this->high = $high;
		$this->low = $low;
		$this->close = $close;
		$this->volume = $volume;
		$this->market = $market;
	}

	/**
	 * @inheritDoc
	 */
	public function previousCandle(): ?ICandle {
		return $this->previousCandle;
	}

	/**
	 * @inheritDoc
	 */
	public function nextCandle(): ?ICandle {
		return $this->nextCandle;
	}

	/**
	 * @inheritDoc
	 */
	public function getOpenTime(): int {
		return $this->timestamp;
	}

	/**
	 * @inheritDoc
	 */
	public function getCloseTime(): int {
		$timeframe = $this->market ? $this->market->getTimeframe() : '15';
		$minutes = (int)$timeframe;
		return $this->timestamp + $minutes * 60;
	}

	/**
	 * @inheritDoc
	 */
	public function getOpenPrice(): float {
		return $this->open;
	}

	/**
	 * @inheritDoc
	 */
	public function getHighPrice(): float {
		return $this->high;
	}

	/**
	 * @inheritDoc
	 */
	public function getLowPrice(): float {
		return $this->low;
	}

	/**
	 * @inheritDoc
	 */
	public function getClosePrice(): float {
		return $this->close;
	}

	/**
	 * @inheritDoc
	 */
	public function getVolume(): float {
		return $this->volume;
	}

	/**
	 * @inheritDoc
	 */
	public function getSize(): float {
		return abs($this->close - $this->open);
	}

	/**
	 * @inheritDoc
	 */
	public function getOpenInterest(): float {
		// Always 0 for spot market
		return 0.0;
	}

	/**
	 * @inheritDoc
	 */
	public function getOpenInterestChange(): float {
		// Always 0 for spot market
		return 0.0;
	}

	/**
	 * @inheritDoc
	 */
	public function getFVG(): ?IFVG {
		// Not implemented yet
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getMarket(): IMarket {
		return $this->market;
	}

	/**
	 * @inheritDoc
	 */
	public function isBullish(): bool {
		return $this->close > $this->open;
	}

	/**
	 * @inheritDoc
	 */
	public function isBearish(): bool {
		return $this->close < $this->open;
	}

	/**
	 * Get the candle body height (absolute difference between open and close).
	 * @return float Body height.
	 */
	public function getBodyHeight(): float {
		return abs($this->close - $this->open);
	}

	/**
	 * Get the upper wick height.
	 * @return float Upper wick height.
	 */
	public function getUpperWickHeight(): float {
		return $this->high - max($this->open, $this->close);
	}

	/**
	 * Get the lower wick height.
	 * @return float Lower wick height.
	 */
	public function getLowerWickHeight(): float {
		return min($this->open, $this->close) - $this->low;
	}

	/**
	 * Set the previous candle reference.
	 * @param ICandle|null $candle Previous candle.
	 * @return void
	 */
	public function setPreviousCandle(?ICandle $candle): void {
		$this->previousCandle = $candle;
	}

	/**
	 * Set the next candle reference.
	 * @param ICandle|null $candle Next candle.
	 * @return void
	 */
	public function setNextCandle(?ICandle $candle): void {
		$this->nextCandle = $candle;
	}

	/**
	 * @inheritDoc
	 */
	public function setMarket(IMarket $market): void {
		$this->market = $market;
	}
}
