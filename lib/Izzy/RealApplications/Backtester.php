<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\CandleRepository;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;

class Backtester extends ConsoleApplication
{
	private const float DEFAULT_INITIAL_BALANCE = 10000.0;

	public function __construct() {
		parent::__construct();
	}

	public function run(): void {
		echo "OK".PHP_EOL;
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
			$chunkEndMs = $endTimeMs;
			$totalSaved = 0;
			while (true) {
				$candles = $exchange->getCandles($pair, $limit, (int)$startTimeMs, (int)$chunkEndMs);
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
		}
	}

	/**
	 * Run backtest on loaded candles: virtual trading with strategy and TP checks.
	 * Prints summary: total trades, initial balance, final balance.
	 * Creates a temporary copy of the positions table for the run and drops it when done.
	 */
	public function runBacktest(): void {
		$this->logger->info('Starting backtest...');
		$exchanges = $this->configuration->connectExchanges($this);
		$pairsForBacktest = $this->configuration->getPairsForBacktest($exchanges);
		if (empty($pairsForBacktest)) {
			$this->logger->info('No pairs with backtest_days found in config.');
			return;
		}
		$this->database->dropTableIfExists('backtest_positions');
		if (!$this->database->createTableLike('backtest_positions', 'positions')) {
			$this->logger->error('Failed to create backtest_positions table.');
			return;
		}
		try {
			$this->runBacktestLoop($pairsForBacktest);
		} finally {
			$this->database->dropTableIfExists('backtest_positions');
		}
	}

	private function runBacktestLoop(array $pairsForBacktest): void {
		$repository = new CandleRepository($this->database);
		$initialBalance = self::DEFAULT_INITIAL_BALANCE;

		foreach ($pairsForBacktest as $entry) {
			$pair = $entry['pair'];
			assert($pair instanceof Pair);
			$days = $pair->getBacktestDays();
			if ($days === null) {
				continue;
			}
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
			$market->initializeConfiguredIndicators();
			$market->initializeStrategy();
			$market->initializeIndicators();
			$n = count($candles);
			for ($i = 0; $i < $n; $i++) {
				$slice = array_slice($candles, 0, $i + 1);
				foreach ($slice as $c) {
					$c->setMarket($market);
				}
				$market->setCandles($slice);
				$currentCandle = $candles[$i];
				$closePrice = $currentCandle->getClosePrice();
				$backtestExchange->setCurrentPriceForMarket($market, Money::from($closePrice));
				$market->calculateIndicators();
				$market->processTrading();
				$high = $currentCandle->getHighPrice();
				$low = $currentCandle->getLowPrice();
				$candleTime = $currentCandle->getOpenTime();
				$where = [
					BacktestStoredPosition::FExchangeName => $market->getExchangeName(),
					BacktestStoredPosition::FTicker => $market->getTicker(),
					BacktestStoredPosition::FMarketType => $market->getMarketType()->value,
					BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
				];
				$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $where, '');
				foreach ($openPositions as $position) {
					$tpPrice = $position->getTakeProfitPrice();
					if ($tpPrice === null) {
						continue;
					}
					$tp = $tpPrice->getAmount();
					$hit = $position->getDirection()->isLong() ? ($high >= $tp) : ($low <= $tp);
					if ($hit) {
						$position->setCurrentPrice(Money::from($tp));
						$position->markFinished($candleTime);
						$position->save();
						$exitValue = $position->getVolume()->getAmount() * $tp;
						$backtestExchange->creditBalance($exitValue);
					}
				}
			}
			$finalBalance = $backtestExchange->getVirtualBalance()->getAmount();
			$table = BacktestStoredPosition::getTableName();
			$marketWhere = [
				BacktestStoredPosition::FExchangeName => $exchangeName,
				BacktestStoredPosition::FTicker => $pair->getTicker(),
				BacktestStoredPosition::FMarketType => $pair->getMarketType()->value,
			];
			$finishedCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::FINISHED->value]));
			$openCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::OPEN->value]));
			$pendingCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::PENDING->value]));
			$this->printBacktestSummary(
				$pair->getTicker(),
				$pair->getTimeframe()->value,
				$exchangeName,
				$initialBalance,
				$finalBalance,
				$finishedCount,
				$openCount,
				$pendingCount
			);
		}
	}

	private function printBacktestSummary(
		string $ticker,
		string $timeframe,
		string $exchangeName,
		float $initialBalance,
		float $finalBalance,
		int $totalTrades,
		int $openCount,
		int $pendingCount
	): void {
		$pnl = $finalBalance - $initialBalance;
		$pnlPercent = $initialBalance > 0 ? (($pnl / $initialBalance) * 100) : 0.0;
		echo PHP_EOL;
		echo "=== Backtest summary: $ticker $timeframe ($exchangeName) ===".PHP_EOL;
		echo "Initial balance: ".number_format($initialBalance, 2)." USDT".PHP_EOL;
		echo "Final balance:   ".number_format($finalBalance, 2)." USDT".PHP_EOL;
		echo "PnL:             ".number_format($pnl, 2)." USDT (".number_format($pnlPercent, 2)."%)".PHP_EOL;
		echo "Total trades (finished): $totalTrades".PHP_EOL;
		echo "Open positions:  $openCount".PHP_EOL;
		echo "Pending:         $pendingCount".PHP_EOL;
		echo PHP_EOL;
	}
}
