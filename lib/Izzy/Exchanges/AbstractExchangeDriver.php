<?php

namespace Izzy\Exchanges;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Indicators\IndicatorFactory;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IStrategy;
use Izzy\Strategies\StrategyFactory;
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
		return $this->exchangeName;
	}

	/**
	 * @inheritDoc
	 */
	public function createMarket(IPair $pair): ?IMarket {
		$candlesData = $this->getCandles($pair, 200);
		if (empty($candlesData)) {
			return null;
		}

		$market = new Market($pair, $this, $this->database);
		$market->setCandles($candlesData);
		return $market;
	}
	
	/**
	 * Update the list of trading pairs and associated markets.
	 * Creates new markets for new pairs and removes markets for unused pairs.
	 */
	protected function updatePairs(): void {
		$this->spotPairs = $this->config->getSpotPairs($this);
		$this->futuresPairs = $this->config->getFuturesPairs($this);
		
		// First, create the absent markets.
		foreach ($this->spotPairs + $this->futuresPairs as $pair) {
			$pairTicker = $pair->getExchangeTicker($this);
			$pairDescription = $pair->getDescription();
			if (!isset($this->markets[$pairTicker])) {
				$market = $this->createMarket($pair);
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

		// Default sleep time of 60 seconds.
		return 60;
	}
	
	/**
	 * Save balance information in the database.
	 * 
	 * @param Money $balance Balance amount to store.
	 * @return bool True if successfully stored, false otherwise.
	 */
	protected function saveBalance(Money $balance): bool {
		$this->logger->info("Balance of {$this->getName()} is: $balance");
		return $this->database->setExchangeBalance($this->getName(), $balance);
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
		$this->logger->info("Driver for exchange {$this->getName()} loaded.");
		
		// Check if this exchange is disabled by the configuration.
		// We return success, because this is completely OK.
		if (!$this->config->isEnabled()) {
			$this->logger->warning("Exchange {$this->getName()} is disabled in the configuration.");
			return 0;
		}

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
	 * Setup strategy for a specific market based on configuration.
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
		
		if (empty($strategyName)) {
			return;
		}
		
		try {
			$strategyParams = $pair->getStrategyParams();
			$strategy = StrategyFactory::create($strategyName, $market, $strategyParams);
			$market->setStrategy($strategy);
			$this->logger->info("Set strategy $strategyName for market $market");
		} catch (\Exception $e) {
			$this->logger->error("Failed to set strategy $strategyName for market $market: " . $e->getMessage());
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
				$indicator = IndicatorFactory::create($market, $indicatorType, $parameters);
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
	public function processMarkets(): void {
		foreach ($this->markets as $ticker => $market) {
			try {
				$this->checkStrategySignals($market);
			} catch (\Exception $e) {
				$this->logger->error("Error processing market {$ticker}: " . $e->getMessage());
			}
		}
	}

	/**
	 * Check strategy signals for a specific market.
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function checkStrategySignals(Market $market): void {
		$strategy = $market->getStrategy();
		if (!$strategy) {
			return;
		}
		
		// Do we already have an open position?
		$hasOpenPosition = $market->hasOpenPosition();
		
		// If no position is open, check for entry signals
		if (!$hasOpenPosition) {
			$this->checkEntrySignals($market, $strategy);
		} else {
			// If position is open, update it (check for DCA, etc.)
			$this->updatePosition($market, $strategy, $market->getCurrentPosition());
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
	 * @param IStrategy $strategy Strategy instance.
	 * @param IPosition $position Current position.
	 * @return void
	 */
	protected function updatePosition(Market $market, IStrategy $strategy, IPosition $position): void {
		// Update current price for position
		$currentPrice = $this->getCurrentPrice($market);
		if ($currentPrice) {
			$position->updateCurrentPrice($currentPrice);
		}

		// Let strategy update position (DCA logic, etc.)
		$strategy->updatePosition($position);
	}

	/**
	 * Execute long entry order.
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function executeLongEntry(Market $market): void {
		$this->logger->info("Long entry detected for {$market}");
		$market->getStrategy()->handleLong($market);
	}

	/**
	 * Execute short entry order.
	 * 
	 * @param Market $market Market instance.
	 * @return void
	 */
	protected function executeShortEntry(Market $market): void {
		$this->logger->info("Short entry detected for {$market}");
		$market->getStrategy()->handleShort($market);
	}

	/**
	 * Calculate quantity based on amount and price.
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount in USDT.
	 * @param float|null $price Price per unit.
	 * @return Money Quantity as string.
	 */
	protected function calculateQuantity(IMarket $market, Money $amount, ?float $price): Money {
		$pair = $market->getPair();
		if ($price) {
			// Limit orders.
			$quantity = $amount->getAmount() / $price;
		} else {
			// For market orders, use a rough estimate.
			$currentPrice = $this->getCurrentPrice($market)->getAmount();
			$quantity = $currentPrice ? ($amount->getAmount() / $currentPrice) : 0.001;
		}
		return Money::from($quantity, $pair->getBaseCurrency());
	}
}
