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
	 * Whether to trade the pair (execute buy/sell orders).
	 * When false, the pair is still monitored (charts, signals) but orders are not placed.
	 * @var bool
	 */
	private bool $tradingEnabled = false;

	/**
	 * Whether to send Telegram notifications for this pair.
	 * @var bool
	 */
	private bool $notificationsEnabled = true;

	/**
	 * Name of the strategy to use for trading.
	 * @var string
	 */
	private string $strategyName = '';

	/**
	 * Strategy parameters as associative array.
	 * @var array
	 */
	private array $strategyParams = [];

	/**
	 * Number of days of history to load for backtesting; null if backtest not configured.
	 * @var int|null
	 */
	private ?int $backtestDays = null;

	/**
	 * Initial balance (USDT) for backtest; null to use default.
	 * @var float|null
	 */
	private ?float $backtestInitialBalance = null;

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
		return IZZY_CHARTS."/$basename";
	}

	/**
	 * Get the chart key for URL generation.
	 * This key corresponds to the filename without extension and path.
	 *
	 * @return string Chart key for URL.
	 */
	public function getChartKey(): string {
		return "{$this->getFilenameTicker()}_{$this->timeframe->value}_{$this->marketType->value}_{$this->exchangeName}";
	}

	/**
	 * Get the title for the chart of this trading pair.
	 * Includes ticker, timeframe, market type, exchange, and current timestamp.
	 *
	 * @return string Chart title string.
	 */
	public function getChartTitle(): string {
		return sprintf("%s %s %s %s %s (%s)",
			$this->getTicker(),
			$this->timeframe->value,
			$this->marketType->value,
			$this->exchangeName,
			date('Y-m-d H:i:s'),
			date_default_timezone_get()
		);
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
	 * Check if Telegram notifications are enabled for this pair.
	 *
	 * @return bool True if notifications are enabled, false otherwise.
	 */
	public function isNotificationsEnabled(): bool {
		return $this->notificationsEnabled;
	}

	/**
	 * Enable or disable Telegram notifications for this pair.
	 *
	 * @param bool $enabled True to enable, false to disable.
	 */
	public function setNotificationsEnabled(bool $enabled): void {
		$this->notificationsEnabled = $enabled;
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
	 * Get strategy parameters as associative array.
	 *
	 * @return array Strategy parameters.
	 */
	public function getStrategyParams(): array {
		return $this->strategyParams;
	}

	/**
	 * Set strategy parameters.
	 *
	 * @param array $params Strategy parameters as associative array.
	 */
	public function setStrategyParams(array $params): void {
		$this->strategyParams = $params;
	}

	/**
	 * Get the number of days of history for backtesting, or null if not set.
	 *
	 * @return int|null Backtest days or null.
	 */
	public function getBacktestDays(): ?int {
		return $this->backtestDays;
	}

	/**
	 * Set the number of days of history for backtesting.
	 *
	 * @param int|null $days Backtest days or null to disable.
	 */
	public function setBacktestDays(?int $days): void {
		$this->backtestDays = $days;
	}

	/**
	 * Get the initial balance (USDT) for backtesting, or null to use default.
	 * @return float|null
	 */
	public function getBacktestInitialBalance(): ?float {
		return $this->backtestInitialBalance;
	}

	/**
	 * Set the initial balance for backtesting.
	 * @param float|null $balance Initial balance in USDT or null.
	 */
	public function setBacktestInitialBalance(?float $balance): void {
		$this->backtestInitialBalance = $balance;
	}

	/**
	 * @inheritDoc
	 */
	public function getExchangeTicker(IExchangeDriver $exchangeDriver): string {
		return $exchangeDriver->pairToTicker($this);
	}

	/**
	 * Get the ticker formatted for use in filenames (underscore-separated).
	 * @return string Filename-safe ticker (e.g., “BTC_USDT”).
	 */
	public function getFilenameTicker(): string {
		return $this->baseCurrency.'_'.$this->quoteCurrency;
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

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		return $this->getBaseCurrency().'/'.$this->getQuoteCurrency();
	}
}
