<?php

namespace Izzy\Financial;

use Izzy\Chart\Chart;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IStrategy;

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

	/**
	 * Registered indicators for this market.
	 * @var IIndicator[]
	 */
	private array $indicators = [];

	/**
	 * Calculated indicator results.
	 * @var IndicatorResult[]
	 */
	private array $indicatorResults = [];

	public function __construct(
		Pair $pair,
		IExchangeDriver $exchange
	) {
		$this->exchange = $exchange;
		$this->marketType = $pair->marketType;
		$this->pair = $pair;
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

	public function isInverseFutures(): bool {
		return $this->marketType->isInverseFutures();
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

	public function updateChart(): void {
		$filename = $this->pair->getChartFilename();
		$chart = new Chart($this);
		$chart->draw();
		$chart->save($filename);
	}

	public function getPair(): Pair {
		return $this->pair;
	}

	public function setPair(Pair $pair): void {
		$this->pair = $pair;
	}

	/**
	 * Add indicator to the market.
	 * 
	 * @param IIndicator $indicator Indicator instance.
	 * @return void
	 */
	public function addIndicator(IIndicator $indicator): void
	{
		$this->indicators[$indicator->getName()] = $indicator;
	}

	/**
	 * Remove indicator from the market.
	 * 
	 * @param string $indicatorName Indicator name.
	 * @return void
	 */
	public function removeIndicator(string $indicatorName): void
	{
		unset($this->indicators[$indicatorName]);
		unset($this->indicatorResults[$indicatorName]);
	}

	/**
	 * Get all registered indicators.
	 * 
	 * @return IIndicator[] Array of indicators.
	 */
	public function getIndicators(): array
	{
		return $this->indicators;
	}

	/**
	 * Check if indicator is registered.
	 * 
	 * @param string $indicatorName Indicator name.
	 * @return bool True if registered, false otherwise.
	 */
	public function hasIndicator(string $indicatorName): bool
	{
		return isset($this->indicators[$indicatorName]);
	}

	/**
	 * Calculate all indicators.
	 * 
	 * @return void
	 */
	public function calculateIndicators(): void
	{
		foreach ($this->indicators as $name => $indicator) {
			$this->indicatorResults[$name] = $indicator->calculate($this);
		}
	}

	/**
	 * Get indicator result.
	 * 
	 * @param string $indicatorName Indicator name.
	 * @return IndicatorResult|null Indicator result or null if not found.
	 */
	public function getIndicatorResult(string $indicatorName): ?IndicatorResult
	{
		return $this->indicatorResults[$indicatorName] ?? null;
	}

	/**
	 * Get all indicator results.
	 * 
	 * @return IndicatorResult[] Array of indicator results.
	 */
	public function getAllIndicatorResults(): array
	{
		return $this->indicatorResults;
	}

	/**
	 * Get latest indicator value.
	 * 
	 * @param string $indicatorName Indicator name.
	 * @return float|null Latest value or null if not found.
	 */
	public function getLatestIndicatorValue(string $indicatorName): ?float
	{
		$result = $this->getIndicatorResult($indicatorName);
		return $result ? $result->getLatestValue() : null;
	}

	/**
	 * Get latest indicator signal.
	 * 
	 * @param string $indicatorName Indicator name.
	 * @return mixed Latest signal or null if not found.
	 */
	public function getLatestIndicatorSignal(string $indicatorName)
	{
		$result = $this->getIndicatorResult($indicatorName);
		return $result ? $result->getLatestSignal() : null;
	}
}
