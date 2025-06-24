<?php

namespace Izzy\Exchanges;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
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
			$pairDescription = $pair->getDescription();
			if (!isset($this->markets[$pair->ticker])) {
				$market = $this->getMarket($pair);
				if ($market) {
					$this->markets[$pair->ticker] = $market;
					$this->logger->info("Created market for pair $pairDescription");
				} else {
					$this->logger->error("Failed to create market for pair $pairDescription");
				}
			}
		}
		
		// Now, remove the markets that are no longer needed.
		foreach ($this->markets as $ticker => $market) {
			if (!isset($this->spotPairs[$ticker]) && !isset($this->futuresPairs[$ticker])) {
				unset($this->markets[$ticker]);
				$this->logger->info("Removed market for pair $ticker");
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
	 * Get market instance for a trading pair.
	 * 
	 * @param Pair $pair Trading pair.
	 * @return Market|null Market instance or null if not found.
	 */
	protected function getMarket(Pair $pair): ?Market {
		// Implementation of getMarketCandles method.
		return null; // Placeholder return, actual implementation needed.
	}

	/**
	 * Update balance information from the exchange.
	 */
	protected function updateBalance(): void {
		// Implementation of updateBalance method.
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
				}
			}
			
			// If the market type is futures, we need to fetch futures candles.
			if ($marketType->isFutures()) {
				if (isset($this->futuresPairs[$ticker])) {
					$pair = $this->futuresPairs[$ticker];
					$candles = $this->getCandles($pair);
					$market->setCandles($candles);
				}
			}
			
			// Setup indicators for this market if not already done.
			$this->setupIndicatorsForMarket($market, $ticker);
			
			// Calculate all indicators.
			$market->calculateIndicators();
		}
	}

	/**
	 * Setup indicators for a specific market based on configuration.
	 * 
	 * @param Market $market Market instance.
	 * @param string $ticker Trading pair ticker.
	 * @return void
	 */
	protected function setupIndicatorsForMarket(Market $market, string $ticker): void
	{
		// Skip if indicators are already set up.
		if (!empty($market->getIndicators())) {
			return;
		}
		
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
				$this->logger->info("Added indicator {$indicatorType} to market {$ticker}");
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
}
