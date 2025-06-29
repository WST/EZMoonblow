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
use Izzy\System\Database\Database;
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
		$this->database = $application->getDatabase();
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

		$market = new Market($this, $pair);
		$market->setCandles($candlesData);
		$market->initializeConfiguredIndicators();
		$market->initializeStrategy(); // sets up strategy indicators as well
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
			
			// Calculate all indicators.
			$market->calculateIndicators();

			// Process trading signals.
			$this->logger->info("Processing trading signals for {$this->getName()}");
			$market->processTrading();
		}
	}
	
	public function getDatabase(): Database {
		return $this->database;
	}
	
	public function getLogger(): Logger {
		return $this->logger;
	}
	
	public function getExchangeConfiguration(): ExchangeConfiguration {
		return $this->config;
	}
}
