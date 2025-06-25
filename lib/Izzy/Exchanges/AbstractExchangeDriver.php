<?php

namespace Izzy\Exchanges;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Financial\StrategyFactory;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IStrategy;
use Izzy\System\Database;
use Izzy\System\Logger;

/**
 * Abstract cryptocurrency exchange driver class.
 * Contains common logic for all cryptocurrency exchanges.
 */
abstract class AbstractExchangeDriver implements IExchangeDriver
{
	/** @var ExchangeConfiguration Configuration settings for the exchange. */
	protected ExchangeConfiguration $config;

	/**
	 * Spot trading pairs.
	 * @var IPair[]
	 */
	protected array $spotPairs = [];

	/**
	 * Futures trading pairs.
	 * @var IPair[]
	 */
	protected array $futuresPairs = [];

	/**
	 * Markets associated with the trading pairs.
	 * @var IMarket[]
	 */
	protected array $markets = [];
	
	/** @var Database Database connection instance. */
	protected Database $database;
	
	/** @var Logger Logger instance for logging operations. */
	protected Logger $logger;

	/**
	 * Constructor for the exchange driver.
	 * 
	 * @param ExchangeConfiguration $config Exchange configuration settings.
	 * @param ConsoleApplication $application Console application instance.
	 */
	public function __construct(ExchangeConfiguration $config, ConsoleApplication $application) {
		$this->config = $config;
		$this->logger = Logger::getLogger();
		$this->database = $application->database;
	}

	/**
	 * Get the name of the exchange.
	 * 
	 * @return string Exchange name.
	 */
	public function getName(): string {
		return $this->config->getName();
	}
	
	/**
	 * Update the list of trading pairs and associated markets.
	 * Creates new markets for new pairs and removes markets for unused pairs.
	 */
	protected function updatePairs(): void {
		$this->spotPairs = $this->config->getSpotPairs();
		$this->futuresPairs = $this->config->getFuturesPairs();
		
		// First, create the absent markets.
		foreach ($this->spotPairs + $this->futuresPairs as $pair) {
			$pairTicker = $pair->getExchangeTicker($this);
			$pairDescription = $pair->getDescription();
			if (!isset($this->markets[$pairTicker])) {
				$market = $this->getMarket($pair);
				if ($market) {
					$this->markets[$pairTicker] = $market;
					$this->logger->info("Created market $market for pair $pairDescription");
				} else {
					$this->logger->error("Failed to create market $market for pair $pairDescription");
				}
			}
		}
		
		// Now, remove the markets that are no longer needed.
		foreach ($this->markets as $ticker => $market) {
			if (!isset($this->spotPairs[$ticker]) && !isset($this->futuresPairs[$ticker])) {
				$marketDescription = $market->getDescription();
				unset($this->markets[$ticker]);
				$this->logger->info("Removed market $marketDescription");
			}
		}
	}

	/**
	 * Update exchange information and data.
	 * 
	 * @return int Number of seconds to sleep after the update.
	 */
	public function update(): int {
		$this->logger->info("Updating total balance information for {$this->getName()}");
		$this->updateBalance();
		
		// Updating the lists of pairs.
		$this->logger->info("Updating the list of pairs for {$this->getName()}");
		$this->updatePairs();
		
		// Update markets.
		$this->logger->info("Updating market data for {$this->getName()}");
		$this->updateMarkets();
		
		// Process trading signals.
		$this->logger->info("Processing trading signals for {$this->getName()}");
		$this->processMarkets();
		
		// Update charts for all markets.
		$this->logger->info("Updating charts for all markets on {$this->getName()}");
		$this->updateCharts();

		if ($this->shouldUpdateOrders()) {
			$this->logger->info("Updating spot limit orders on {$this->getName()}");
			$this->updateSpotLimitOrders();
		}

		// Default sleep time of 60 seconds.
		return 60;
	}

	/**
	 * Get current balance from the exchange.
	 * 
	 * @return float Current balance amount.
	 */
	protected function getBalance(): float {
		// Implementation of getBalance method.
		return 0.0; // Placeholder return, actual implementation needed.
	}
	
	/**
	 * Set balance information in the database.
	 * 
	 * @param Money $balance Balance amount to store.
	 * @return bool True if successfully stored, false otherwise.
	 */
	protected function setBalance(Money $balance): bool {
		return $this->database->setExchangeBalance($this->exchangeName, $balance);
	}

	/**
	 * Check if orders should be updated.
	 * 
	 * @return bool True if orders should be updated, false otherwise.
	 */
	protected function shouldUpdateOrders(): bool {
		// Implementation of shouldUpdateOrders method.
		return false; // Placeholder return, actual implementation needed.
	}

	/**
	 * Update spot limit orders on the exchange.
	 */
	protected function updateSpotLimitOrders(): void {
		// Implementation of updateSpotLimitOrders method.
	}

	/**
	 * Fork into a child process and run the exchange driver.
	 * 
	 * @return int Process ID of the child process, or -1 on failure.
	 */
	public function run(): int {
		$pid = pcntl_fork();
		if($pid) {
			return $pid;
		}

		if(!$this->connect()) {
			return -1;
		}

		// Connect to database in the new process.
		$this->database->connect();

		// Log that the driver has been successfully loaded.
		$this->logger->info("Driver for exchange {$this->exchangeName} loaded");

		// Main loop.
		while(true) {
			$timeout = $this->update();
			sleep($timeout);
		}
	}
	
	/**
	 * Update charts for all markets.
	 */
	public function updateCharts(): void {
		foreach ($this->markets as $ticker => $market) {
			$market->updateChart();
		}
	}

	/**
	 * Update market information for all markets.
	 * Fetches fresh candle data and calculates indicators.
	 */
	protected function updateMarkets(): void {
		foreach ($this->markets as $ticker => $market) {
			// First, let's determine the type of market.
			$marketType = $market->getMarketType();
			
			// If the market type is spot, we need to fetch spot candles.
			if ($marketType->isSpot()) {
				if (isset($this->spotPairs[$ticker])) {
					$pair = $this->spotPairs[$ticker];
					$candles = $this->getCandles($pair);
					$market->setCandles($candles);
					
					// Set strategy for this market if not already set
					$this->setupStrategyForMarket($market);
				}
			}
			
			// If the market type is futures, we need to fetch futures candles.
			if ($marketType->isFutures()) {
				if (isset($this->futuresPairs[$ticker])) {
					$pair = $this->futuresPairs[$ticker];
					$candles = $this->getCandles($pair);
					$market->setCandles($candles);
					
					// Set strategy for this market if not already set
					$this->setupStrategyForMarket($market);
				}
			}
			
			// Setup indicators for this market if not already done.
			$this->setupIndicatorsForMarket($market);
			
			// Calculate all indicators.
			$market->calculateIndicators();
		}
	}

	/**
	 * Setup strategy for a specific market based on pair configuration.
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function setupStrategyForMarket(Market $market): void {
		// Skip if strategy is already set.
		if ($market->getStrategy()) {
			return;
		}

		$pair = $market->getPair();
		$strategyName = $pair->getStrategyName();
		
		try {
			$strategy = StrategyFactory::create($strategyName, $market);
			$market->setStrategy($strategy);
			$this->logger->info("Set strategy {$strategyName} for market $market");
		} catch (\Exception $e) {
			$this->logger->error("Failed to set strategy {$strategyName} for market $market: " . $e->getMessage());
		}
	}

	/**
	 * Setup indicators for a specific market based on configuration.
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function setupIndicatorsForMarket(Market $market): void {
		// Skip if indicators are already set up.
		if (!empty($market->getIndicators())) {
			return;
		}
		
		$ticker = $market->getTicker();
		
		// Get indicators configuration for this pair.
		$indicatorsConfig = $this->getIndicatorsConfig($ticker);
		
		if (empty($indicatorsConfig)) {
			return;
		}
		
		// Create and add indicators.
		foreach ($indicatorsConfig as $indicatorType => $parameters) {
			try {
				$indicator = \Izzy\Indicators\IndicatorFactory::create($indicatorType, $parameters);
				$market->addIndicator($indicator);
				$this->logger->info("Added indicator {$indicatorType} to market $market");
			} catch (\Exception $e) {
				$this->logger->error("Failed to add indicator {$indicatorType} to market {$ticker}: " . $e->getMessage());
			}
		}
	}

	/**
	 * Get indicators configuration for a trading pair.
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @return array Indicators configuration.
	 */
	protected function getIndicatorsConfig(string $ticker): array
	{
		// Try to get indicators from spot pairs first
		$spotConfig = $this->config->getIndicatorsConfig($ticker, MarketTypeEnum::SPOT);
		if (!empty($spotConfig)) {
			return $spotConfig;
		}
		
		// Try to get indicators from futures pairs
		$futuresConfig = $this->config->getIndicatorsConfig($ticker, MarketTypeEnum::FUTURES);
		if (!empty($futuresConfig)) {
			return $futuresConfig;
		}
		
		// Return empty array if no configuration found
		return [];
	}

	/**
	 * Process all markets for trading signals.
	 * This method is called periodically to check for trading opportunities.
	 * 
	 * @return void
	 */
	public function processMarkets(): void
	{
		foreach ($this->markets as $ticker => $market) {
			try {
				$this->checkStrategySignals($market, $ticker);
			} catch (\Exception $e) {
				$this->logger->error("Error processing market {$ticker}: " . $e->getMessage());
			}
		}
	}

	/**
	 * Check strategy signals for a specific market.
	 * 
	 * @param Market $market Market instance.
	 * @param IPair $pair
	 * @return void
	 */
	protected function checkStrategySignals(Market $market, IPair $pair): void {
		$strategy = $market->getStrategy();
		if (!$strategy) {
			return;
		}

		// Get current position
		$currentPosition = $this->getCurrentPosition(pair);
		
		// If no position is open, check for entry signals
		if (!$currentPosition || !$currentPosition->isOpen()) {
			$this->checkEntrySignals($market, $strategy);
		} else {
			// If position is open, update it (check for DCA, etc.)
			$this->updatePosition($market, $strategy, $currentPosition);
		}
	}

	/**
	 * Check for entry signals (shouldLong, shouldShort).
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function checkEntrySignals(Market $market): void {
		$strategy = $market->getStrategy();
		if (!$strategy) {
			$this->logger->warning("No strategy set for market {$market->getTicker()}");
			return;
		}
		
		// Check for long entry signal
		if ($strategy->shouldLong()) {
			$this->logger->info("Long signal detected for {$market}");
			$this->executeLongEntry($market);
			return;
		}

		// Check for short entry signal (only for futures)
		if ($market->isFutures() && $strategy->shouldShort()) {
			$this->logger->info("Short signal detected for {$market}");
			$this->executeShortEntry($market);
			return;
		}
	}

	/**
	 * Update existing position (DCA, etc.).
	 * 
	 * @param Market $market Market instance.
	 * @param string $ticker Trading pair ticker.
	 * @param IStrategy $strategy Strategy instance.
	 * @param IPosition $position Current position.
	 * @return void
	 */
	protected function updatePosition(Market $market, string $ticker, IStrategy $strategy, IPosition $position): void
	{
		// Update current price for position
		$currentPrice = $this->getCurrentPrice($ticker);
		if ($currentPrice) {
			$position->updateCurrentPrice($currentPrice);
		}

		// Let strategy update position (DCA logic, etc.)
		$strategy->updatePosition();
	}

	/**
	 * Execute long entry order.
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function executeLongEntry(Market $market): void {
		$pair = $market->getPair();
		
		// Get current price
		$currentPrice = $this->getCurrentPrice($pair);
		if (!$currentPrice) {
			$this->logger->error("Failed to get current price for {$market}");
			return;
		}

		// Calculate position size (for now, use a fixed amount)
		// TODO: Make this configurable
		$positionSize = new Money(10.0, 'USDT'); // $10 position size

		// Open long position
		if ($this->openLong($market, $positionSize)) {
			$this->logger->info("Successfully opened long position for {$market} at price {$currentPrice}");
		} else {
			$this->logger->error("Failed to open long position for {$market}");
		}
	}

	/**
	 * Execute short entry order.
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function executeShortEntry(Market $market): void {
		$pair = $market->getPair();
		// Get current price
		$currentPrice = $this->getCurrentPrice($pair);
		if (!$currentPrice) {
			$this->logger->error("Failed to get current price for {$market}");
			return;
		}

		// Calculate position size (for now, use a fixed amount)
		// TODO: Make this configurable
		$positionSize = new Money(10.0, 'USDT'); // $10 position size

		// Open short position
		if ($this->openShort($pair, $positionSize)) {
			$this->logger->info("Successfully opened short position for {$market} at price {$currentPrice}");
		} else {
			$this->logger->error("Failed to open short position for {$market}");
		}
	}

	/**
	 * Execute DCA (Dollar Cost Averaging) buy order.
	 * 
	 * @param string $ticker Trading pair ticker.
	 * @param Money $amount Amount to buy.
	 * @return void
	 */
	protected function executeDCA(string $ticker, Money $amount): void {
		if ($this->buyAdditional($ticker, $amount)) {
			$this->logger->info("Successfully executed DCA buy for {$ticker}: {$amount}");
		} else {
			$this->logger->error("Failed to execute DCA buy for {$ticker}: {$amount}");
		}
	}
}
