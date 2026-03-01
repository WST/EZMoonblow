<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\AbstractConsoleApplication;
use Izzy\Backtest\BacktestBalanceChart;
use Izzy\Backtest\BacktestEngine;
use Izzy\Backtest\BacktestResultRecord;
use Izzy\Backtest\OptimizationSuggestionRecord;
use Izzy\Enums\BacktestModeEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Configuration\StrategyConfiguration;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\CandleRepository;
use Izzy\Financial\Pair;
use Izzy\Financial\StrategyFactory;
use Izzy\System\Logger;

/**
 * Optimizer daemon.
 *
 * Periodically backtests traded pairs with randomly mutated strategy parameters.
 * When a mutation improves PnL, an optimization suggestion is saved to the database.
 */
class Optimizer extends AbstractConsoleApplication
{
	private const float DEFAULT_INITIAL_BALANCE = 10000.0;
	private const int BASELINE_FRESHNESS_DAYS = 7;

	private int $ticksPerCandle;

	public function __construct() {
		parent::__construct();
		$this->ticksPerCandle = $this->configuration->getOptimizerTicksPerCandle();
	}

	public function run(): void {
		$this->logger->info('Starting Optimizer daemon...');
		$this->startHeartbeat();

		$intervalHours = $this->configuration->getOptimizerIntervalHours();
		$minBacktestDays = $this->configuration->getOptimizerMinBacktestDays();
		$optimizableParams = $this->configuration->getOptimizableParams();

		if (empty($optimizableParams)) {
			$this->logger->warning('No optimizable params configured. Optimizer will idle.');
		}

		$exchanges = $this->configuration->connectExchanges($this);
		$pairsForBacktest = $this->configuration->getPairsForBacktest($exchanges);

		if (empty($pairsForBacktest)) {
			$this->logger->warning('No pairs with backtest_days found. Optimizer will idle.');
		}

		$pairIndex = 0;

		while (!self::$shouldStop) {
			$this->beat(['iteration' => $pairIndex]);

			if (empty($pairsForBacktest) || empty($optimizableParams)) {
				$this->interruptibleSleep($intervalHours * 3600);
				continue;
			}

			$entry = $pairsForBacktest[$pairIndex % count($pairsForBacktest)];
			$pairIndex++;

			/** @var Pair $pair */
			$pair = $entry['pair'];
			$realExchange = $entry['exchange'];
			$ticker = $pair->getTicker();
			$strategyName = $pair->getStrategyName() ?? '';

			$this->logger->info("Optimizer iteration for $ticker ($strategyName)");

			try {
				$this->optimizePair($pair, $realExchange, $optimizableParams, $minBacktestDays);
			} catch (\Throwable $e) {
				$this->logger->error("Optimizer error for $ticker: " . $e->getMessage());
			}

			$this->logger->info("Sleeping for $intervalHours hour(s)...");
			$this->interruptibleSleep($intervalHours * 3600);
		}
	}

	/**
	 * Run one optimization cycle for a single pair.
	 */
	private function optimizePair(
		Pair $pair,
		mixed $realExchange,
		array $optimizableParams,
		int $minBacktestDays,
	): void {
		$ticker = $pair->getTicker();
		$strategyParams = $pair->getStrategyParams();
		$strategyName = $pair->getStrategyName() ?? '';

		// Build a name → parameter object map from the strategy definition.
		$strategyClass = StrategyFactory::getStrategyClass($strategyName);
		$paramObjects = [];
		if ($strategyClass !== null && method_exists($strategyClass, 'getParameters')) {
			foreach ($strategyClass::getParameters() as $paramObj) {
				$paramObjects[$paramObj->getName()] = $paramObj;
			}
		}

		// Intersect with params that actually exist in this strategy's config
		// and have a known parameter object (so we can delegate mutation).
		$eligibleParams = array_values(array_filter(
			$optimizableParams,
			fn(string $p) => isset($strategyParams[$p]) && isset($paramObjects[$p]),
		));

		if (empty($eligibleParams)) {
			$this->logger->info("No eligible params to optimize for $ticker.");
			return;
		}

		// 1. Find or run the baseline backtest.
		$baseline = $this->findRecentBaseline($pair, $minBacktestDays);
		if ($baseline === null) {
			$this->logger->info("No recent baseline for $ticker, running fresh backtest...");
			$baseline = $this->runBacktestForPair($pair, $realExchange, $strategyParams, BacktestModeEnum::AUTO);
			if ($baseline === null) {
				$this->logger->warning("Baseline backtest failed for $ticker.");
				return;
			}
		}

		$baselinePnl = (float) $baseline->getPnlPercent();
		$this->logger->info("Baseline PnL for $ticker: " . number_format($baselinePnl, 2) . '%');

		// 2. Pick a random param and let the parameter object mutate itself.
		$paramName = $eligibleParams[array_rand($eligibleParams)];
		$originalValue = $strategyParams[$paramName];
		$mutatedValue = $paramObjects[$paramName]->mutate($originalValue);

		if ($mutatedValue === $originalValue) {
			$this->logger->info("Mutation of $paramName produced no change ($originalValue), skipping.");
			return;
		}

		$this->logger->info("Mutating $paramName: $originalValue -> $mutatedValue");

		// 3. Run backtest with the mutated parameter over the same time window.
		$mutatedParams = $strategyParams;
		$mutatedParams[$paramName] = $mutatedValue;

		$mutatedPair = $this->clonePairWithParams($pair, $mutatedParams);

		// Force same simulation window as baseline.
		$mutatedPair->setBacktestDays($pair->getBacktestDays());

		$mutatedResult = $this->runBacktestForPair(
			$mutatedPair,
			$realExchange,
			$mutatedParams,
			BacktestModeEnum::AUTO,
			$baseline->getSimStart(),
			$baseline->getSimEnd(),
		);

		if ($mutatedResult === null) {
			$this->logger->warning("Mutated backtest failed for $ticker.");
			return;
		}

		$mutatedPnl = (float) $mutatedResult->getPnlPercent();
		$this->logger->info("Mutated PnL for $ticker: " . number_format($mutatedPnl, 2) . '%');

		// 4. Compare and save suggestion if better.
		if ($mutatedPnl > $baselinePnl) {
			$improvement = $mutatedPnl - $baselinePnl;
			$this->logger->info("Improvement found! +$improvement% for $ticker ($paramName: $originalValue -> $mutatedValue)");

			$suggestedXml = BacktestResultRecord::buildPairXml($mutatedPair);

			OptimizationSuggestionRecord::saveFromData(
				database: $this->database,
				ticker: $pair->getTicker(),
				exchangeName: $pair->getExchangeName(),
				marketType: $pair->getMarketType()->value,
				timeframe: $pair->getTimeframe()->value,
				strategy: $pair->getStrategyName() ?? '',
				mutatedParam: $paramName,
				originalValue: (string) $originalValue,
				mutatedValue: (string) $mutatedValue,
				baselinePnlPercent: $baselinePnl,
				mutatedPnlPercent: $mutatedPnl,
				baselineBacktestId: $baseline->getId(),
				mutatedBacktestId: $mutatedResult->getId(),
				suggestedXml: $suggestedXml,
			);
		} else {
			$this->logger->info("No improvement for $ticker ($paramName: $originalValue -> $mutatedValue): $mutatedPnl% vs $baselinePnl%");
		}
	}

	/**
	 * Find a recent baseline backtest for this pair+strategy+params, or null.
	 */
	private function findRecentBaseline(Pair $pair, int $minBacktestDays): ?BacktestResultRecord {
		$cutoff = time() - self::BASELINE_FRESHNESS_DAYS * 86400;
		$minDuration = $minBacktestDays * 86400;

		$where = [
			BacktestResultRecord::FTicker => $pair->getTicker(),
			BacktestResultRecord::FExchangeName => $pair->getExchangeName(),
			BacktestResultRecord::FMarketType => $pair->getMarketType()->value,
			BacktestResultRecord::FTimeframe => $pair->getTimeframe()->value,
			BacktestResultRecord::FStrategy => $pair->getStrategyName() ?? '',
		];

		$results = BacktestResultRecord::loadFiltered(
			$this->database,
			$where,
			BacktestResultRecord::FCreatedAt . ' DESC',
			20,
		);

		$expectedConfig = new StrategyConfiguration(
			$pair->getStrategyName() ?? '',
			$pair->getStrategyParams(),
		);

		foreach ($results as $r) {
			if ($r->getCreatedAt() < $cutoff) {
				continue;
			}
			$duration = $r->getSimEnd() - $r->getSimStart();
			if ($duration < $minDuration) {
				continue;
			}
			$recordConfig = new StrategyConfiguration(
				$r->getStrategy(),
				$r->getStrategyParams(),
			);
			if ($expectedConfig->equals($recordConfig)) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * Run a full backtest for a pair and return the saved record.
	 */
	private function runBacktestForPair(
		Pair $pair,
		mixed $realExchange,
		array $strategyParams,
		BacktestModeEnum $mode,
		?int $forceSimStart = null,
		?int $forceSimEnd = null,
	): ?BacktestResultRecord {
		$repository = new CandleRepository($this->database);
		$days = $pair->getBacktestDays();
		if ($days === null) {
			return null;
		}

		$initialBalance = $pair->getBacktestInitialBalance() ?? self::DEFAULT_INITIAL_BALANCE;
		$exchangeName = $pair->getExchangeName();
		$exchangeConfig = $this->configuration->getExchangeConfiguration($exchangeName);
		if (!$exchangeConfig) {
			return null;
		}

		if ($forceSimStart !== null && $forceSimEnd !== null) {
			$startTime = $forceSimStart;
			$endTime = $forceSimEnd;
		} else {
			$endTime = time();
			$startTime = $endTime - $days * 86400;
		}

		$candles = $repository->getCandles($pair, $startTime, $endTime);
		if (empty($candles)) {
			$this->logger->warning("No candles for {$pair->getTicker()}.");
			return null;
		}

		$suffix = 'opt_' . uniqid();
		BacktestStoredPosition::setTableSuffix($suffix);
		$tableName = BacktestStoredPosition::getTableName();

		$this->database->dropTableIfExists($tableName);
		if (!$this->database->createTableLike($tableName, 'positions')) {
			$this->logger->error("Failed to create $tableName (source table 'positions' may not exist).");
			BacktestStoredPosition::resetTableSuffix();
			return null;
		}
		if (!$this->database->tableExists($tableName)) {
			$this->logger->error("Table $tableName was reportedly created but does not exist. Possible disk or permission issue.");
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
			$backtestPair->setStrategyParams($strategyParams);
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

			BacktestResultRecord::saveFromResult($this->database, $result, $chartPng, $mode);
			$lastId = $this->database->lastInsertId();
			$this->database->commit();

			if ($lastId === false || $lastId <= 0) {
				$this->logger->warning("Could not retrieve last insert ID after saving backtest result.");
				return null;
			}
			return BacktestResultRecord::loadById($this->database, $lastId);
		} finally {
			Logger::getLogger()->setBacktestMode(false);
			$this->database->dropTableIfExists($tableName);
			BacktestStoredPosition::resetTableSuffix();
		}
	}

	/**
	 * Clone a pair with different strategy params.
	 */
	private function clonePairWithParams(Pair $original, array $newParams): Pair {
		$pair = new Pair(
			$original->getTicker(),
			$original->getTimeframe(),
			$original->getExchangeName(),
			$original->getMarketType(),
		);
		$pair->setStrategyName($original->getStrategyName());
		$pair->setStrategyParams($newParams);
		$pair->setTradingEnabled(true);
		$pair->setBacktestDays($original->getBacktestDays());
		$pair->setBacktestInitialBalance(
			$original->getBacktestInitialBalance() ?? self::DEFAULT_INITIAL_BALANCE
		);
		return $pair;
	}
}
