<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\AbstractConsoleApplication;
use Izzy\Backtest\BacktestBalanceChart;
use Izzy\Backtest\BacktestEngine;
use Izzy\Backtest\BacktestEventWriter;
use Izzy\Backtest\BacktestResultRecord;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\CandleRepository;
use Izzy\Financial\Pair;
use Izzy\Financial\StrategyFactory;
use Izzy\System\Logger;

class Backtester extends AbstractConsoleApplication
{
	private const float DEFAULT_INITIAL_BALANCE = 10000.0;
	private const int DEFAULT_TICKS_PER_CANDLE = 4;

	private int $ticksPerCandle = self::DEFAULT_TICKS_PER_CANDLE;

	public function __construct() {
		parent::__construct();
	}

	public function run(): void {
		echo "OK" . PHP_EOL;
	}

	/**
	 * Load historical candles for all pairs that have backtest_days set.
	 * Fetches from exchange in chunks and saves to the database.
	 */
	public function loadCandles(): void {
		$this->logger->info('Loading candles for backtest pairs...');
		$exchanges = $this->configuration->connectExchanges($this);
		$pairsForBacktest = $this->configuration->getPairsForBacktest($exchanges);
		if (empty($pairsForBacktest)) {
			$this->logger->info('No pairs with backtest_days found in config.');
			return;
		}
		$repository = new CandleRepository($this->database);
		$limit = 1000;
		foreach ($pairsForBacktest as $entry) {
			$exchange = $entry['exchange'];
			$pair = $entry['pair'];
			assert($pair instanceof Pair);
			$days = $pair->getBacktestDays();
			if ($days === null) {
				continue;
			}
			$endTimeMs = time() * 1000;
			$startTimeMs = $endTimeMs - $days * 24 * 3600 * 1000;
			$exchangeName = $pair->getExchangeName();
			$ticker = $pair->getTicker();
			$marketType = $pair->getMarketType()->value;
			$timeframe = $pair->getTimeframe()->value;
			$this->logger->info("Loading candles: $ticker $timeframe $marketType ($exchangeName) for $days days");

			// Wrap the entire pair loading (main TF + additional TFs) in a transaction
			// so all candles appear atomically.
			$this->database->beginTransaction();
			try {
				$chunkEndMs = $endTimeMs;
				$totalSaved = 0;
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
				$this->logger->info("Saved $totalSaved candles for $ticker $timeframe $marketType");

				// Load additional timeframes required by the strategy (e.g. daily candles for EMA).
				$strategyName = $pair->getStrategyName();
				if ($strategyName) {
					$strategyClass = StrategyFactory::getStrategyClass($strategyName);
					if ($strategyClass !== null) {
						$additionalTimeframes = $strategyClass::requiredTimeframes();
						foreach ($additionalTimeframes as $tf) {
							$tfValue = $tf->value;
							if ($tfValue === $timeframe) {
								continue; // Already loaded above.
							}
							$this->logger->info("Loading additional timeframe candles: $ticker $tfValue $marketType ($exchangeName) for $days days");
							$tfPair = new Pair($ticker, $tf, $exchangeName, $pair->getMarketType());
							$tfChunkEndMs = $endTimeMs;
							$tfTotalSaved = 0;
							while (true) {
								$tfCandles = $exchange->getCandles($tfPair, $limit, (int) $startTimeMs, (int) $tfChunkEndMs);
								if (empty($tfCandles)) {
									break;
								}
								$saved = $repository->saveCandles($exchangeName, $ticker, $marketType, $tfValue, $tfCandles);
								$tfTotalSaved += $saved;
								$oldestMs = $tfCandles[0]->getOpenTime() * 1000;
								if ($oldestMs <= $startTimeMs || count($tfCandles) < $limit) {
									break;
								}
								$tfChunkEndMs = $oldestMs - 1;
							}
							$this->logger->info("Saved $tfTotalSaved candles for $ticker $tfValue $marketType");
						}
					}
				}

				$this->database->commit();
			} catch (\Throwable $e) {
				$this->database->rollBack();
				$this->logger->error("Failed to load candles for $ticker: " . $e->getMessage());
			}
		}
	}

	/**
	 * Run backtest on loaded candles: virtual trading with strategy and TP checks.
	 * Prints summary: total trades, initial balance, final balance.
	 * Creates a temporary copy of the positions table for the run and drops it when done.
	 */
	public function runBacktest(): void {
		$this->logger->info('Starting backtest...');

		if (defined('IZZY_TICKS_PER_CANDLE') && IZZY_TICKS_PER_CANDLE !== null) {
			$this->ticksPerCandle = IZZY_TICKS_PER_CANDLE;
			$this->logger->info("Using {$this->ticksPerCandle} ticks per candle (from --ticks CLI option)");
		}

		$exchanges = $this->configuration->connectExchanges($this);
		$pairsForBacktest = $this->configuration->getPairsForBacktest($exchanges);
		if (empty($pairsForBacktest)) {
			$this->logger->info('No pairs with backtest_days found in config.');
			return;
		}

		$suffix = 'cli_' . uniqid();
		BacktestStoredPosition::setTableSuffix($suffix);
		$tableName = BacktestStoredPosition::getTableName();

		$this->database->dropTableIfExists($tableName);
		if (!$this->database->createTableLike($tableName, 'positions')) {
			$this->logger->error("Failed to create {$tableName} table.");
			BacktestStoredPosition::resetTableSuffix();
			return;
		}
		try {
			Logger::getLogger()->setBacktestMode(true);
			$this->runBacktestLoop($pairsForBacktest);
		} finally {
			Logger::getLogger()->setBacktestMode(false);
			$this->database->dropTableIfExists($tableName);
			BacktestStoredPosition::resetTableSuffix();
		}
	}

	/**
	 * Run a backtest from the web UI with event streaming.
	 *
	 * @param string $sessionId Unique session identifier for the JSONL event file.
	 * @param array $config Backtest configuration from the web form:
	 *   - pair: string (e.g. "BAN/USDT")
	 *   - exchangeName: string (e.g. "Bybit")
	 *   - marketType: string ("futures" or "spot")
	 *   - timeframe: string (e.g. "4h")
	 *   - strategy: string (e.g. "EZMoonblowSEBoll")
	 *   - params: array (strategy parameters)
	 *   - days: int (backtest period in days)
	 *   - initialBalance: float (starting balance)
	 *   - leverage: int (leverage multiplier)
	 *   - ticksPerCandle: int (optional, default 4)
	 */
	public function runWebBacktest(string $sessionId, array $config): void {
		$eventsFile = $this->getEventFilePath($sessionId);
		$writer = new BacktestEventWriter($eventsFile);

		$suffix = 'web_' . $sessionId;
		BacktestStoredPosition::setTableSuffix($suffix);
		$tableName = BacktestStoredPosition::getTableName();

		try {
			$exchanges = $this->configuration->connectExchanges($this);

			$timeframe = TimeFrameEnum::from($config['timeframe']);
			$marketType = MarketTypeEnum::from($config['marketType']);
			$pair = new Pair($config['pair'], $timeframe, $config['exchangeName'], $marketType);
			$pair->setStrategyName($config['strategy']);
			$pair->setStrategyParams($config['params'] ?? []);
			$pair->setBacktestDays($config['days']);
			$pair->setBacktestInitialBalance($config['initialBalance']);

			$this->ticksPerCandle = max(4, (int)($config['ticksPerCandle'] ?? self::DEFAULT_TICKS_PER_CANDLE));
			$pair->setBacktestTicksPerCandle($this->ticksPerCandle);

			// Find the real exchange driver for instrument info.
			$realExchange = $exchanges[$config['exchangeName']] ?? null;

			$this->database->dropTableIfExists($tableName);
			if (!$this->database->createTableLike($tableName, 'positions')) {
				$writer->writeError("Failed to create {$tableName} table.");
				$writer->writeDone();
				BacktestStoredPosition::resetTableSuffix();
				return;
			}

			Logger::getLogger()->setBacktestMode(true);
			$this->runBacktestLoop(
				[['pair' => $pair, 'exchange' => $realExchange]],
				$writer,
				$sessionId,
			);
		} catch (\Throwable $e) {
			$writer->writeError($e->getMessage());
		} finally {
			Logger::getLogger()->setBacktestMode(false);
			$this->database->dropTableIfExists($tableName);
			BacktestStoredPosition::resetTableSuffix();
			$writer->writeDone();
		}
	}

	/**
	 * Get the path to the JSONL event file for a given session.
	 *
	 * @param string $sessionId Session identifier.
	 * @return string Absolute path.
	 */
	public static function getEventFilePath(string $sessionId): string {
		return sys_get_temp_dir() . "/backtest-{$sessionId}-events.jsonl";
	}

	/**
	 * Get the path to the config JSON file for a given session.
	 *
	 * @param string $sessionId Session identifier.
	 * @return string Absolute path.
	 */
	public static function getConfigFilePath(string $sessionId): string {
		return sys_get_temp_dir() . "/backtest-{$sessionId}-config.json";
	}

	public static function getStopFilePath(string $sessionId): string {
		return sys_get_temp_dir() . "/backtest-{$sessionId}-stop";
	}

	/**
	 * @param array $pairsForBacktest Array of ['pair' => Pair, 'exchange' => IExchangeDriver|null].
	 * @param BacktestEventWriter|null $writer Optional event writer for web UI streaming.
	 * @param string|null $sessionId Web backtest session ID (for abort support).
	 */
	private function runBacktestLoop(array $pairsForBacktest, ?BacktestEventWriter $writer = null, ?string $sessionId = null): void {
		$repository = new CandleRepository($this->database);
		$engine = new BacktestEngine($this->database, $this->logger);

		$this->database->beginTransaction();
		foreach ($pairsForBacktest as $entry) {
			$pair = $entry['pair'];
			assert($pair instanceof Pair);
			$days = $pair->getBacktestDays();
			if ($days === null) {
				continue;
			}
			$initialBalance = $pair->getBacktestInitialBalance() ?? self::DEFAULT_INITIAL_BALANCE;
			if ($pair->getBacktestTicksPerCandle() !== null) {
				$this->ticksPerCandle = $pair->getBacktestTicksPerCandle();
			}
			$pair->setBacktestTicksPerCandle($this->ticksPerCandle);
			$exchangeName = $pair->getExchangeName();
			$exchangeConfig = $this->configuration->getExchangeConfiguration($exchangeName);
			if (!$exchangeConfig) {
				continue;
			}
			$backtestExchange = new BacktestExchange(
				$this->database,
				$this->logger,
				$exchangeName,
				$exchangeConfig,
				$initialBalance
			);

			$endTime = time();
			$startTime = $endTime - $days * 24 * 3600;
			$candles = $repository->getCandles($pair, $startTime, $endTime);
			if (empty($candles)) {
				$this->logger->warning("No candles for {$pair->getTicker()} {$pair->getTimeframe()->value}; run load-candles first.");
				continue;
			}
			$backtestPair = new Pair(
				$pair->getTicker(),
				$pair->getTimeframe(),
				$pair->getExchangeName(),
				$pair->getMarketType()
			);
			$backtestPair->setStrategyName($pair->getStrategyName());
			$backtestPair->setStrategyParams($pair->getStrategyParams());
			$backtestPair->setTradingEnabled(true);
			$market = $backtestExchange->createMarket($backtestPair);
			if (!$market) {
				continue;
			}

			$realExchange = $entry['exchange'];
			try {
				$backtestExchange->setTickSize($market, $realExchange->getTickSize($market));
				$backtestExchange->setQtyStep($market, $realExchange->getQtyStep($market));
			} catch (\Throwable $e) {
				$this->logger->warning("Could not fetch instrument info from {$exchangeName}: " . $e->getMessage());
			}
			$backtestExchange->setFeeRate($realExchange->getTakerFee($pair->getMarketType()));

			$market->initializeStrategy();
			$market->initializeIndicators();

			$n = count($candles);
			$log = Logger::getLogger();
			$log->backtestProgress("{$pair->getTicker()}: $n candles, balance " . number_format($initialBalance, 2) . " USDT");

			$this->database->resetQueryTimer();
			$simWallStart = hrtime(true);

			$state = $engine->runSimulation(
				$pair, $candles, $backtestExchange, $market,
				$this->ticksPerCandle, $writer, $sessionId,
			);

			$simWallTimeMs = (hrtime(true) - $simWallStart) / 1e6;
			$sqlTimeMs = $this->database->getCumulativeQueryTimeMs();
			$indicatorTimeMs = $state->indicatorTimeNs / 1e6;

			$result = $engine->collectResults(
				$state, $pair, $backtestPair, $backtestExchange,
				$exchangeName, $initialBalance, $candles,
			);
			echo $result;

			if ($writer !== null) {
				$pnl = $result->financial->getPnl();
				$pnlPercent = $result->financial->getPnlPercent();
				$total = $result->trades->wins + $result->trades->losses;
				$winRate = $total > 0 ? ($result->trades->wins / $total) * 100 : 0.0;
				$writer->writeResult([
					'initialBalance' => $result->financial->initialBalance,
					'finalBalance' => $result->financial->finalBalance,
					'pnl' => $pnl,
					'pnlPercent' => round($pnlPercent, 2),
					'maxDrawdown' => $result->financial->maxDrawdown,
					'liquidated' => $result->financial->liquidated,
					'trades' => $result->trades->finished,
					'wins' => $result->trades->wins,
					'losses' => $result->trades->losses,
					'breakevenLocks' => $result->trades->breakevenLocks,
					'winRate' => round($winRate, 1),
					'sharpe' => $result->risk?->sharpe,
					'sortino' => $result->risk?->sortino,
					'coinPriceStart' => $result->financial->coinPriceStart,
					'coinPriceEnd' => $result->financial->coinPriceEnd,
					'totalFees' => round($result->financial->totalFees, 2),
					'longestLosingDuration' => $result->financial->longestLosingDuration,
					'perfSimTimeMs' => round($simWallTimeMs),
					'perfSqlTimeMs' => round($sqlTimeMs),
					'perfIndicatorTimeMs' => round($indicatorTimeMs),
				]);
			}

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

			BacktestResultRecord::saveFromResult($this->database, $result, $chartPng);
		}
		$this->database->commit();

		$queryStats = Logger::getLogger()->getQueryStats();
		$totalSec = number_format($queryStats['totalMs'] / 1000, 2);
		echo "\n\033[90mDB stats: {$queryStats['count']} queries, {$totalSec}s total query time\033[0m\n";
	}
}
