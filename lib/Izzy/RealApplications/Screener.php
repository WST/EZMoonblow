<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\AbstractConsoleApplication;
use Izzy\Backtest\BacktestBalanceChart;
use Izzy\Backtest\BacktestEngine;
use Izzy\Backtest\BacktestResultRecord;
use Izzy\Enums\BacktestModeEnum;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\CandleRepository;
use Izzy\Financial\Pair;
use Izzy\Financial\StrategyFactory;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\System\Logger;

/**
 * Screener daemon.
 *
 * Periodically picks a random top trading pair from the exchange,
 * loads candles, and runs a backtest with a randomly chosen strategy
 * from the configured list. Results are saved with Auto mode.
 */
class Screener extends AbstractConsoleApplication
{
	private const int BACKTEST_DAYS = 100;
	private const float DEFAULT_INITIAL_BALANCE = 1000.0;

	private int $ticksPerCandle;

	public function __construct() {
		parent::__construct();
		$this->ticksPerCandle = $this->configuration->getScreenerTicksPerCandle();
	}

	public function run(): void {
		$this->logger->info('Starting Screener daemon...');
		$this->startHeartbeat();

		$intervalMinutes = $this->configuration->getScreenerIntervalMinutes();
		$strategies = $this->configuration->getScreenerStrategies();
		$topPairsLimit = $this->configuration->getScreenerTopPairs();
		$initialBalance = $this->configuration->getScreenerInitialBalance();
		$marketTypeStr = $this->configuration->getScreenerMarketType();
		$timeframe = $this->configuration->getScreenerTimeframe();

		if (empty($strategies)) {
			$this->logger->warning('No screener strategies configured. Screener will idle.');
		}

		$exchanges = $this->configuration->connectExchanges($this);
		if (empty($exchanges)) {
			$this->logger->error('No exchanges available. Screener will idle.');
		}

		$exchange = reset($exchanges);
		$exchangeName = $exchange ? $exchange->getName() : '';

		$category = match ($marketTypeStr) {
			'spot' => 'spot',
			default => 'linear',
		};
		$marketType = match ($marketTypeStr) {
			'spot' => MarketTypeEnum::SPOT,
			default => MarketTypeEnum::FUTURES,
		};

		while (!self::$shouldStop) {
			$this->beat();

			if (empty($strategies) || !$exchange) {
				$this->interruptibleSleep($intervalMinutes * 60);
				continue;
			}

			try {
				$this->logger->info("Fetching top $topPairsLimit pairs by volume...");
				$pairs = $exchange->getTopPairsByVolume($topPairsLimit, $category);

				if (empty($pairs)) {
					$this->logger->warning('No pairs returned from exchange.');
					$this->interruptibleSleep($intervalMinutes * 60);
					continue;
				}

				$ticker = $pairs[array_rand($pairs)];
				$strategy = $strategies[array_rand($strategies)];
				$strategyName = $strategy['name'];
				$strategyParams = $strategy['params'];

				$this->logger->info("Screening $ticker with $strategyName");

				$this->screenPair(
					$ticker,
					$timeframe,
					$exchangeName,
					$marketType,
					$strategyName,
					$strategyParams,
					$initialBalance,
					$exchange,
				);
			} catch (\Throwable $e) {
				$this->logger->error("Screener error: " . $e->getMessage());
			}

			$this->logger->info("Sleeping for $intervalMinutes minute(s)...");
			$this->interruptibleSleep($intervalMinutes * 60);
		}
	}

	/**
	 * Run one screening iteration: load candles if needed, then backtest.
	 */
	private function screenPair(
		string $ticker,
		TimeFrameEnum $timeframe,
		string $exchangeName,
		MarketTypeEnum $marketType,
		string $strategyName,
		array $strategyParams,
		float $initialBalance,
		IExchangeDriver $exchange,
	): void {
		$repository = new CandleRepository($this->database);
		$endTime = time();
		$startTime = $endTime - self::BACKTEST_DAYS * 86400;

		$pair = new Pair($ticker, $timeframe, $exchangeName, $marketType);
		$pair->setStrategyName($strategyName);
		$pair->setStrategyParams($strategyParams);
		$pair->setTradingEnabled(true);
		$pair->setBacktestDays(self::BACKTEST_DAYS);
		$pair->setBacktestInitialBalance($initialBalance);

		// Load candles if not already cached.
		if (!$repository->hasCandles($pair, $timeframe->value, $startTime, $endTime)) {
			$this->logger->info("Loading candles for $ticker $timeframe->value from exchange...");
			$this->loadCandlesFromExchange($exchange, $pair, $repository, $startTime, $endTime);
		}

		// Load additional timeframes required by the strategy.
		$strategyClass = StrategyFactory::getStrategyClass($strategyName);
		if ($strategyClass !== null) {
			foreach ($strategyClass::requiredTimeframes() as $tf) {
				if ($tf->value === $timeframe->value) {
					continue;
				}
				if (!$repository->hasCandles($pair, $tf->value, $startTime, $endTime)) {
					$this->logger->info("Loading additional timeframe candles: $ticker $tf->value");
					$tfPair = new Pair($ticker, $tf, $exchangeName, $marketType);
					$this->loadCandlesFromExchange($exchange, $tfPair, $repository, $startTime, $endTime);
				}
			}
		}

		$candles = $repository->getCandles($pair, $startTime, $endTime);
		if (empty($candles)) {
			$this->logger->warning("No candles available for $ticker after loading attempt.");
			return;
		}

		$result = $this->runBacktest($pair, $candles, $exchange, $initialBalance);
		if ($result !== null) {
			$pnl = number_format((float) $result->getPnlPercent(), 2);
			$this->logger->info("Screener result for $ticker ($strategyName): PnL $pnl%");
		} else {
			$this->logger->warning("Backtest failed for $ticker ($strategyName).");
		}
	}

	/**
	 * Load candles from exchange in chunks and save to repository.
	 */
	private function loadCandlesFromExchange(
		IExchangeDriver $exchange,
		Pair $pair,
		CandleRepository $repository,
		int $startTime,
		int $endTime,
	): void {
		$limit = 1000;
		$startTimeMs = $startTime * 1000;
		$endTimeMs = $endTime * 1000;
		$chunkEndMs = $endTimeMs;
		$totalSaved = 0;
		$ticker = $pair->getTicker();
		$exchangeName = $pair->getExchangeName();
		$marketType = $pair->getMarketType()->value;
		$timeframe = $pair->getTimeframe()->value;

		$this->database->beginTransaction();
		try {
			while (true) {
				$candles = $exchange->getCandles($pair, $limit, (int) $startTimeMs, (int) $chunkEndMs);
				if (empty($candles)) {
					break;
				}
				$saved = $repository->saveCandles($exchangeName, $ticker, $marketType, $timeframe, $candles);
				$totalSaved += $saved;
				$oldestOpenTimeMs = $candles[0]->getOpenTime() * 1000;
				if ($oldestOpenTimeMs <= $startTimeMs || count($candles) < $limit) {
					break;
				}
				$chunkEndMs = $oldestOpenTimeMs - 1;
			}
			$this->database->commit();
			$this->logger->info("Saved $totalSaved candles for $ticker $timeframe $marketType");
		} catch (\Throwable $e) {
			$this->database->rollBack();
			$this->logger->error("Failed to load candles for $ticker: " . $e->getMessage());
		}
	}

	/**
	 * Run a full backtest and return the saved record.
	 */
	private function runBacktest(
		Pair $pair,
		array $candles,
		IExchangeDriver $realExchange,
		float $initialBalance,
	): ?BacktestResultRecord {
		$exchangeName = $pair->getExchangeName();
		$exchangeConfig = $this->configuration->getExchangeConfiguration($exchangeName);
		if (!$exchangeConfig) {
			return null;
		}

		$suffix = 'scr_' . uniqid();
		BacktestStoredPosition::setTableSuffix($suffix);
		$tableName = BacktestStoredPosition::getTableName();

		$this->database->dropTableIfExists($tableName);
		if (!$this->database->createTableLike($tableName, 'positions')) {
			$this->logger->error("Failed to create $tableName.");
			BacktestStoredPosition::resetTableSuffix();
			return null;
		}

		try {
			Logger::getLogger()->setBacktestMode(true);

			$backtestExchange = new BacktestExchange(
				$this->database,
				$this->logger,
				$exchangeName,
				$exchangeConfig,
				$initialBalance,
			);

			$backtestPair = new Pair(
				$pair->getTicker(),
				$pair->getTimeframe(),
				$pair->getExchangeName(),
				$pair->getMarketType(),
			);
			$backtestPair->setStrategyName($pair->getStrategyName());
			$backtestPair->setStrategyParams($pair->getStrategyParams());
			$backtestPair->setTradingEnabled(true);
			$backtestPair->setBacktestDays($pair->getBacktestDays());
			$backtestPair->setBacktestInitialBalance($initialBalance);

			$market = $backtestExchange->createMarket($backtestPair);
			if (!$market) {
				return null;
			}

			try {
				$backtestExchange->setTickSize($market, $realExchange->getTickSize($market));
				$backtestExchange->setQtyStep($market, $realExchange->getQtyStep($market));
			} catch (\Throwable $e) {
				$this->logger->warning("Could not fetch instrument info: " . $e->getMessage());
			}
			$backtestExchange->setFeeRate($realExchange->getTakerFee($pair->getMarketType()));

			$market->initializeStrategy();
			$market->initializeIndicators();

			$this->database->beginTransaction();

			$engine = new BacktestEngine($this->database, $this->logger);
			$state = $engine->runSimulation(
				$pair, $candles, $backtestExchange, $market,
				$this->ticksPerCandle,
				shouldStop: fn() => self::$shouldStop,
			);

			$result = $engine->collectResults(
				$state, $pair, $backtestPair, $backtestExchange,
				$exchangeName, $initialBalance, $candles,
			);

			$simStartTime = !empty($candles) ? (int) $candles[0]->getOpenTime() : time();
			$simEndTime = $state->lastCandle !== null
				? ((int) $state->lastCandle->getOpenTime() + $state->candleDuration - 1)
				: time();

			$chartPng = BacktestBalanceChart::generate(
				$state->balanceSnapshots,
				$simStartTime,
				$simEndTime,
				$state->candleDuration,
			);

			BacktestResultRecord::saveFromResult($this->database, $result, $chartPng, BacktestModeEnum::AUTO);
			$lastId = $this->database->lastInsertId();
			$this->database->commit();

			if ($lastId === false || $lastId <= 0) {
				$this->logger->warning("Could not retrieve last insert ID after saving screener result.");
				return null;
			}
			return BacktestResultRecord::loadById($this->database, $lastId);
		} finally {
			Logger::getLogger()->setBacktestMode(false);
			$this->database->dropTableIfExists($tableName);
			BacktestStoredPosition::resetTableSuffix();
		}
	}
}
