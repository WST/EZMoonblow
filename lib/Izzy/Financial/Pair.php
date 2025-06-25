<?php

namespace Izzy\Financial;

use InvalidArgumentException;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IPair;
use Izzy\Traits\HasMarketTypeTrait;

/**
 * Trading pair representation.
 * Contains information about a specific trading pair including ticker, timeframe, exchange, and market type.
 */
class Pair implements IPair
{
	use HasMarketTypeTrait;

	/**
	 * Base currency of the pair, e.g. BTC in BTC/USDT.
	 * @var string
	 */
	public string $baseCurrency = '';

	/**
	 * Quote currency of the pair, e.g. USDT in BTC/USDT.
	 * @var string
	 */
	public string $quoteCurrency = '';

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
	 * Whether to monitor the pair (draw charts, emit signals).
	 * @var bool
	 */
	private bool $monitoringEnabled = false;
	
	/**
	 * Whether to trade the pair (execute buy/sell orders).
	 * @var bool
	 */
	private bool $tradingEnabled = false;
	
	/**
	 * Name of the strategy to use for trading.
	 * @var string
	 */
	private string $strategyName = '';

	/**
	 * Constructor for creating a new trading pair.
	 * 
	 * @param string $ticker Trading pair ticker (e.g., "BTC/USDT"), including “/” is important.
	 * @param TimeFrameEnum $timeFrame Timeframe for the pair (e.g., 15m, 1h).
	 * @param string $exchangeName Name of the exchange (e.g., "Bybit", "Gate").
	 * @param MarketTypeEnum $marketType Market type (spot, futures, etc.).
	 */
	public function __construct(
		string $ticker,
		TimeFrameEnum $timeFrame,
		string $exchangeName,
		MarketTypeEnum $marketType
	) {
		$this->setTicker($ticker);
		$this->timeframe = $timeFrame;
		$this->exchangeName = $exchangeName;
		$this->marketType = $marketType;
	}

	/**
	 * Check if this is a spot trading pair.
	 * 
	 * @return bool True if this is a spot pair, false otherwise.
	 */
	public function isSpot(): bool {
		return $this->marketType->isSpot();
	}

	/**
	 * Check if this is a futures trading pair.
	 * 
	 * @return bool True if this is a futures pair, false otherwise.
	 */
	public function isFutures(): bool {
		return $this->marketType->isFutures();
	}

	/**
	 * Check if this is an inverse futures trading pair.
	 * Currently not implemented, always returns false.
	 * 
	 * @return bool Always returns false.
	 */
	public function isInverseFutures(): bool {
		return false;
	}
	
	/**
	 * Get a human-readable description of the trading pair.
	 * Example: "BTC/USDT 15m spot Bybit".
	 * 
	 * @return string Formatted description of the pair.
	 */
	public function getDescription(): string {
		return "{$this->getTicker()} {$this->timeframe->value} {$this->marketType->value} {$this->exchangeName}";
	}
	
	/**
	 * Get the exchange name for this trading pair.
	 * 
	 * @return string Exchange name.
	 */
	public function getExchangeName(): string {
		return $this->exchangeName;
	}

	/**
	 * Set the exchange name for this trading pair.
	 * 
	 * @param string $exchangeName New exchange name.
	 */
	public function setExchangeName(string $exchangeName): void {
		$this->exchangeName = $exchangeName;
	}

	/**
	 * Get the ticker symbol for this trading pair.
	 * 
	 * @return string Trading pair ticker.
	 */
	public function getTicker(): string {
		return "{$this->baseCurrency}/{$this->quoteCurrency}";
	}

	/**
	 * Set the ticker symbol for this trading pair.
	 * 
	 * @param string $ticker New ticker symbol.
	 */
	public function setTicker(string $ticker): void {
		$parts = [];
		if (!preg_match('#^([A-Z0-9]+)/([A-Z0-9]+)$#', $ticker, $parts)) {
			throw new InvalidArgumentException("Invalid ticker format: '$ticker'. Expected format is 'BASE/QUOTE'.");
		}
		$this->baseCurrency = $parts[1];
		$this->quoteCurrency = $parts[2];
	}

	/**
	 * Get the timeframe for this trading pair.
	 * 
	 * @return TimeFrameEnum Trading pair timeframe.
	 */
	public function getTimeframe(): TimeFrameEnum {
		return $this->timeframe;
	}

	/**
	 * Set the timeframe for this trading pair.
	 * 
	 * @param TimeFrameEnum $timeframe New timeframe.
	 */
	public function setTimeframe(TimeFrameEnum $timeframe): void {
		$this->timeframe = $timeframe;
	}

	/**
	 * Get the market type for this trading pair.
	 * 
	 * @return MarketTypeEnum Market type enum.
	 */
	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}

	/**
	 * Set the market type for this trading pair.
	 * 
	 * @param MarketTypeEnum $marketType New market type.
	 */
	public function setMarketType(MarketTypeEnum $marketType): void {
		$this->marketType = $marketType;
	}

	/**
	 * Get the filename for the chart image of this trading pair.
	 * Generates a filename based on ticker, timeframe, market type, and exchange.
	 * 
	 * @return string Chart filename with full path.
	 */
	public function getChartFilename(): string {
		$basename = "{$this->getFilenameTicker()}_{$this->timeframe->value}_{$this->marketType->value}_{$this->exchangeName}.png";
		return IZZY_CHARTS . "/$basename";
	}
	
	/**
	 * Get the title for the chart of this trading pair.
	 * Includes ticker, timeframe, market type, exchange, and current timestamp.
	 * 
	 * @return string Chart title string.
	 */
	public function getChartTitle(): string {
		return sprintf("%s %s %s %s %s",
			$this->getTicker(),
			$this->timeframe->value,
			$this->marketType->value,
			$this->exchangeName,
			date('Y-m-d H:i:s')
		);
	}

	/**
	 * Check if monitoring is enabled for this trading pair.
	 * 
	 * @return bool True if monitoring is enabled, false otherwise.
	 */
	public function isMonitoringEnabled(): bool {
		return $this->monitoringEnabled;
	}

	/**
	 * Enable or disable monitoring for this trading pair.
	 * 
	 * @param bool $monitor True to enable monitoring, false to disable.
	 */
	public function setMonitoringEnabled(bool $monitor): void {
		$this->monitoringEnabled = $monitor;
	}

	/**
	 * Check if trading is enabled for this trading pair.
	 * 
	 * @return bool True if trading is enabled, false otherwise.
	 */
	public function isTradingEnabled(): bool {
		return $this->tradingEnabled;
	}

	/**
	 * Enable or disable trading for this trading pair.
	 * 
	 * @param bool $tradingEnabled True to enable trading, false to disable.
	 */
	public function setTradingEnabled(bool $tradingEnabled): void {
		$this->tradingEnabled = $tradingEnabled;
	}

	/**
	 * Get the strategy name associated with this trading pair.
	 * 
	 * @return string Strategy name or empty string if not set.
	 */
	public function getStrategyName(): string {
		return $this->strategyName;
	}

	/**
	 * Set the strategy name for this trading pair.
	 * 
	 * @param string $strategyName Name of the trading strategy to use.
	 */
	public function setStrategyName(string $strategyName): void {
		$this->strategyName = $strategyName;
	}

	/**
	 * @inheritDoc
	 */
	public function getExchangeTicker(IExchangeDriver $exchangeDriver): string {
		return $exchangeDriver->pairToTicker($this);
	}

	/**
	 * @inheritDoc
	 */
	public function getFilenameTicker(): string {
		return $this->baseCurrency . '_' . $this->quoteCurrency;
	}

	/**
	 * @inheritDoc
	 */
	public function getBaseCurrency(): string {
		return $this->baseCurrency;
	}

	/**
	 * @inheritDoc
	 */
	public function getQuoteCurrency(): string {
		return $this->quoteCurrency;
	}

	public function __toString(): string {
		return $this->getBaseCurrency() . '/' . $this->getQuoteCurrency();
	}
}
