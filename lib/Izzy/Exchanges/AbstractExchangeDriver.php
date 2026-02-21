<?php

namespace Izzy\Exchanges;

use Izzy\AbstractApplications\IzzyApplication;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Market;
use Izzy\Financial\MarketCandleRepository;
use Izzy\Financial\Money;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\System\Database\Database;
use Izzy\System\Logger;
use Izzy\System\SystemHeartbeat;

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

	/** Timestamp of the last chart update. */
	private int $lastChartUpdate = 0;

	/** Minimum interval between chart updates (seconds). */
	private const int CHART_UPDATE_INTERVAL = 300;

	/**
	 * Constructor for the exchange driver.
	 *
	 * @param ExchangeConfiguration $config Exchange configuration settings.
	 * @param IzzyApplication $application Application instance.
	 */
	public function __construct(ExchangeConfiguration $config, IzzyApplication $application) {
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
		$candlesData = $this->getCandles($pair);
		if (empty($candlesData)) {
			return null;
		}

		$market = new Market($this, $pair);
		$market->setCandles($candlesData);
		$market->initializeStrategy();
		$market->initializeIndicators();

		$candleRepo = new MarketCandleRepository($this->database);
		$candleRepo->saveCandles(
			$this->getName(),
			$pair->getTicker(),
			$pair->getMarketType()->value,
			$pair->getTimeframe()->value,
			$candlesData
		);

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

		// Update charts periodically (every 5 minutes, not every cycle).
		$now = time();
		if ($now - $this->lastChartUpdate >= self::CHART_UPDATE_INTERVAL) {
			$this->logger->info("Updating charts for all markets on {$this->getName()}");
			$this->updateCharts();
			$this->lastChartUpdate = $now;
		}

		// Default sleep time of 30 seconds.
		return 30;
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
		if ($pid) {
			return $pid;
		}

		if (!$this->connect()) {
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

		// Start heartbeat monitoring for Trader component.
		$heartbeat = new SystemHeartbeat($this->database, 'Trader');
		$heartbeat->start();

		// Register signal handlers for graceful shutdown.
		$shouldStop = false;
		pcntl_async_signals(true);
		$shutdownHandler = function (int $signal) use ($heartbeat, &$shouldStop): void {
			$signalName = $signal === SIGINT ? 'SIGINT' : 'SIGTERM';
			$this->logger->info("Received $signalName, shutting down Trader gracefully...");
			$shouldStop = true;
			$heartbeat->stop();
			exit(0);
		};
		pcntl_signal(SIGINT, $shutdownHandler);
		pcntl_signal(SIGTERM, $shutdownHandler);

		// Main loop.
		while (!$shouldStop) {
			// Update heartbeat with exchange info.
			$heartbeat->beat(['exchange' => $this->getName()]);

			$timeout = $this->update();

			// Interruptible sleep - check for shutdown signal every second.
			for ($i = 0; $i < $timeout && !$shouldStop; $i++) {
				sleep(1);
			}
		}
	}

	/**
	 * Update charts for all markets.
	 */
	public function updateCharts(): void {
		foreach ($this->markets as $market) {
			$market->updateChart();
		}
	}

	/**
	 * Update market information for all markets.
	 * Fetches fresh candle data and calculates indicators.
	 */
	protected function updateMarkets(): void {
		$candleRepo = new MarketCandleRepository($this->database);

		foreach ($this->markets as $ticker => $market) {
			$marketType = $market->getMarketType();
			$pair = null;

			if ($marketType->isSpot() && isset($this->spotPairs[$ticker])) {
				$pair = $this->spotPairs[$ticker];
			} elseif ($marketType->isFutures() && isset($this->futuresPairs[$ticker])) {
				$pair = $this->futuresPairs[$ticker];
			}

			if ($pair !== null) {
				$candles = $this->getCandles($pair);
				$market->setCandles($candles);

				if (!empty($candles)) {
					$candleRepo->saveCandles(
						$this->getName(),
						$pair->getTicker(),
						$pair->getMarketType()->value,
						$pair->getTimeframe()->value,
						$candles
					);
				}
			}

			$market->calculateIndicators();

			if ($market->getPair()->isTradingEnabled()) {
				$this->logger->info("Processing trading signals for $market");
				$market->processTrading();
			}
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

	/**
	 * @inheritDoc
	 */
	public function getMaxPositions(): ?int {
		return $this->config->getMaxPositions();
	}

	/**
	 * @inheritDoc
	 *
	 * Default: not supported. Override in exchange-specific drivers.
	 */
	public function switchMarginMode(IMarket $market, \Izzy\Enums\MarginModeEnum $mode): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 *
	 * Default: not supported. Override in exchange-specific drivers.
	 */
	public function placeLimitClose(
		IMarket $market,
		Money $volume,
		Money $price,
		PositionDirectionEnum $direction,
	): string|false {
		return false;
	}

	/**
	 * @inheritDoc
	 *
	 * Default: zero fee. Override in exchange-specific drivers.
	 */
	public function getTakerFee(MarketTypeEnum $marketType): float {
		return 0.0;
	}

	/**
	 * @inheritDoc
	 *
	 * Default: zero fee. Override in exchange-specific drivers.
	 */
	public function getMakerFee(MarketTypeEnum $marketType): float {
		return 0.0;
	}
}
