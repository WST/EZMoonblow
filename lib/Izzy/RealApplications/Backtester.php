<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\CandleRepository;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\System\Logger;

class Backtester extends ConsoleApplication
{
	private const float DEFAULT_INITIAL_BALANCE = 10000.0;

	public function __construct()
	{
		parent::__construct();
	}

	public function run(): void
	{
		echo "OK" . PHP_EOL;
	}

	/**
	 * Load historical candles for all pairs that have backtest_days set.
	 * Fetches from exchange in chunks and saves to the database.
	 */
	public function loadCandles(): void
	{
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
		}
	}

	/**
	 * Run backtest on loaded candles: virtual trading with strategy and TP checks.
	 * Prints summary: total trades, initial balance, final balance.
	 * Creates a temporary copy of the positions table for the run and drops it when done.
	 */
	public function runBacktest(): void
	{
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
			Logger::getLogger()->setBacktestMode(true);
			$this->runBacktestLoop($pairsForBacktest);
		} finally {
			Logger::getLogger()->setBacktestMode(false);
			$this->database->dropTableIfExists('backtest_positions');
		}
	}

	private function runBacktestLoop(array $pairsForBacktest): void
	{
		$repository = new CandleRepository($this->database);

		foreach ($pairsForBacktest as $entry) {
			$pair = $entry['pair'];
			assert($pair instanceof Pair);
			$days = $pair->getBacktestDays();
			if ($days === null) {
				continue;
			}
			$initialBalance = $pair->getBacktestInitialBalance() ?? self::DEFAULT_INITIAL_BALANCE;
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
			$ticker = $pair->getTicker();
			$log = Logger::getLogger();
			$log->backtestProgress("$ticker: $n candles, balance " . number_format($initialBalance, 2) . " USDT");
			$progressStep = max(1, (int) ($n / 20));
			$liquidated = false;
			$lastCandle = null;
			$maxDrawdown = 0.0; // Track the deepest unrealized PnL dip during the simulation.
			/*
			 * ============================================================
			 *  INTRA-CANDLE TIME SIMULATION
			 * ============================================================
			 *
			 * In the real Trader, Market::processTrading() is called every
			 * 60 seconds regardless of the candle timeframe. This means
			 * the bot can open a new position seconds after the previous
			 * one was closed by TP, even within the same candle.
			 *
			 * To model this behaviour without generating millions of fake
			 * 1-minute ticks, we split each candle into 4 synthetic ticks
			 * that approximate the price path inside the candle:
			 *
			 *   Tick 0  time = candleOpen               price = open
			 *   Tick 1  time = candleOpen + duration/3   price = low  (bullish) | high (bearish)
			 *   Tick 2  time = candleOpen + duration*2/3 price = high (bullish) | low  (bearish)
			 *   Tick 3  time = candleOpen + duration - 1 price = close
			 *
			 * A candle is bullish when close >= open (price went up overall),
			 * bearish otherwise. The assumed intra-candle price path:
			 *
			 *   Bullish:  open ──▼ low ──▲ high ──► close   (dips first, then rallies)
			 *   Bearish:  open ──▲ high ──▼ low  ──► close   (rallies first, then drops)
			 *
			 * On EVERY tick we execute the full trading cycle:
			 *   1. Set simulation time and current market price
			 *   2. Recalculate indicators (they see the latest candle slice)
			 *   3. Call processTrading() — checks for existing position,
			 *      fires entry signals, executes DCA updatePosition, etc.
			 *   4. Fill any pending DCA limit orders whose price is reached
			 *   5. Check Take-Profit hits on open positions
			 *   6. Check liquidation (balance + unrealized PnL <= 0)
			 *
			 * Because processTrading() runs on every tick, a TP hit on
			 * tick 1 is immediately followed by processTrading() on tick 2
			 * of the SAME candle — the strategy can open a new position
			 * without waiting for the next candle, just like in production.
			 * ============================================================
			 */
			$candleDuration = $pair->getTimeframe()->toSeconds();

			for ($i = 0; $i < $n; $i++) {
				$slice = array_slice($candles, 0, $i + 1);
				foreach ($slice as $c) {
					$c->setMarket($market);
				}
				$market->setCandles($slice);
				$currentCandle = $candles[$i];
				$lastCandle = $currentCandle;
				$candleTime = (int) $currentCandle->getOpenTime();

				$openPrice = $currentCandle->getOpenPrice();
				$highPrice = $currentCandle->getHighPrice();
				$lowPrice = $currentCandle->getLowPrice();
				$closePrice = $currentCandle->getClosePrice();
				$isBullish = $closePrice >= $openPrice;

				// Build 4 ticks: [time, price] pairs that approximate the price path.
				$ticks = $isBullish
					? [
						[$candleTime,                                    $openPrice],
						[$candleTime + (int) ($candleDuration / 3),      $lowPrice],
						[$candleTime + (int) ($candleDuration * 2 / 3),  $highPrice],
						[$candleTime + $candleDuration - 1,              $closePrice],
					]
					: [
						[$candleTime,                                    $openPrice],
						[$candleTime + (int) ($candleDuration / 3),      $highPrice],
						[$candleTime + (int) ($candleDuration * 2 / 3),  $lowPrice],
						[$candleTime + $candleDuration - 1,              $closePrice],
					];

				foreach ($ticks as [$tickTime, $tickPrice]) {
					// --- 1. Set simulation time and price ---
					$backtestExchange->setSimulationTime($tickTime);
					$log->setBacktestSimulationTime($tickTime);
					$backtestExchange->setCurrentPriceForMarket($market, Money::from($tickPrice));

					// --- 2. Recalculate indicators ---
					$market->calculateIndicators();

					// --- 3. processTrading: entry signals / position updates ---
					$market->processTrading();

					// --- 4. Fill pending DCA limit orders ---
					foreach (array_values($backtestExchange->getPendingLimitOrders($market)) as $order) {
						$orderPrice = $order['price'];
						$filled = $order['direction']->isLong()
							? ($tickPrice <= $orderPrice)
							: ($tickPrice >= $orderPrice);
						if ($filled) {
							$backtestExchange->addToPosition($market, $order['volumeBase'], $order['price']);
							$backtestExchange->removePendingLimitOrder($market, $order['orderId']);
						}
					}

					// Reload positions from DB after DCA fills / new opens.
					$where = [
						BacktestStoredPosition::FExchangeName => $market->getExchangeName(),
						BacktestStoredPosition::FTicker => $market->getTicker(),
						BacktestStoredPosition::FMarketType => $market->getMarketType()->value,
						BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
					];
					$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $where, '');

					// --- 5. Check Take-Profit hits ---
					foreach ($openPositions as $position) {
						$tpPrice = $position->getTakeProfitPrice();
						if ($tpPrice === null) {
							continue;
						}
						$tp = $tpPrice->getAmount();
						$hit = $position->getDirection()->isLong()
							? ($tickPrice >= $tp)
							: ($tickPrice <= $tp);
						if (!$hit) {
							continue;
						}
						$position->setCurrentPrice($tpPrice);
						$profitMoney = $position->getUnrealizedPnL();
						$profit = $profitMoney->getAmount();
						if ($profit <= 0) {
							continue;
						}
						$position->markFinished($tickTime);
						$position->save();
						$backtestExchange->creditBalance($profit);
						$backtestExchange->clearPendingLimitOrders($market);
						$balanceAfter = $backtestExchange->getVirtualBalance()->getAmount();
						$dir = $position->getDirection()->value;
						$log->backtestProgress("  TP HIT $ticker $dir @ " . number_format($tp, 4) . " PnL " . number_format($profit, 2) . " USDT -> balance " . number_format($balanceAfter, 2) . " USDT");
					}

					// --- 6. Liquidation check ---
					$balance = $backtestExchange->getVirtualBalance()->getAmount();
					$unrealizedPnl = 0.0;
					// Re-fetch positions (some may have been closed by TP above).
					$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $where, '');
					foreach ($openPositions as $position) {
						$vol = $position->getVolume()->getAmount();
						$entry = $position->getAverageEntryPrice()->getAmount();
						if ($position->getDirection()->isLong()) {
							$unrealizedPnl += $vol * ($tickPrice - $entry);
						} else {
							$unrealizedPnl += $vol * ($entry - $tickPrice);
						}
					}
					if ($unrealizedPnl < $maxDrawdown) {
						$maxDrawdown = $unrealizedPnl;
					}
					if ($balance + $unrealizedPnl <= 0) {
						$liquidated = true;
						$dateStr = date('Y-m-d H:i', $tickTime);
						$log->backtestProgress("  LIQUIDATION at candle " . ($i + 1) . "/$n ($dateStr): balance " . number_format($balance, 2) . " USDT + unrealized PnL " . number_format($unrealizedPnl, 2) . " USDT <= 0");
						$this->logger->warning("Backtest stopped: liquidated at candle " . ($i + 1) . " $dateStr.");
						break 2; // Exit both tick and candle loops.
					}
				}

				// Progress log after all ticks of this candle are processed.
				if ($i % $progressStep === 0 || $i === $n - 1) {
					$balance = $backtestExchange->getVirtualBalance()->getAmount();
					$date = date('Y-m-d H:i', $candleTime);
					$log->backtestProgress("  Candle " . ($i + 1) . "/$n $date close=" . number_format($closePrice, 4) . " balance=" . number_format($balance, 2) . " USDT");
				}
			}

			$finalBalance = $backtestExchange->getVirtualBalance()->getAmount();
			if ($liquidated) {
				$finalBalance = 0.0; // Positions closed at a loss; balance is wiped out.
			}
			$table = BacktestStoredPosition::getTableName();
			$marketWhere = [
				BacktestStoredPosition::FExchangeName => $exchangeName,
				BacktestStoredPosition::FTicker => $pair->getTicker(),
				BacktestStoredPosition::FMarketType => $pair->getMarketType()->value,
			];
			$finishedCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::FINISHED->value]));
			$openCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::OPEN->value]));
			$pendingCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::PENDING->value]));

			$openPositionsData = [];
			$lastClose = $lastCandle !== null ? $lastCandle->getClosePrice() : 0.0;
			$simEndTime = $lastCandle !== null ? ((int) $lastCandle->getOpenTime() + $candleDuration - 1) : time();
			$simStartTime = !empty($candles) ? (int) $candles[0]->getOpenTime() : $simEndTime;
			$whereOpen = array_merge($marketWhere, [
				BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
			]);
			$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $whereOpen, '');
			foreach ($openPositions as $pos) {
				$vol = $pos->getVolume()->getAmount();
				$entry = $pos->getAverageEntryPrice()->getAmount();
				$unrealizedPnl = $pos->getDirection()->isLong()
					? $vol * ($lastClose - $entry)
					: $vol * ($entry - $lastClose);
				$createdAt = $pos->getCreatedAt();
				$timeHangingSec = $simEndTime - $createdAt;
				$openPositionsData[] = [
					'direction' => $pos->getDirection()->value,
					'entry' => $entry,
					'volume' => $vol,
					'created_at' => $createdAt,
					'unrealized_pnl' => $unrealizedPnl,
					'time_hanging_sec' => $timeHangingSec,
				];
			}

			// Compute trade duration stats from finished positions.
			$whereFinished = array_merge($marketWhere, [
				BacktestStoredPosition::FStatus => PositionStatusEnum::FINISHED->value,
			]);
			$finishedPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $whereFinished, BacktestStoredPosition::FCreatedAt . ' ASC');
			$tradeDurations = [];
			$tradeIntervals = []; // [created_at, finished_at] for each position (finished or still open)
			foreach ($finishedPositions as $pos) {
				$created = $pos->getCreatedAt();
				$finished = $pos->getFinishedAt();
				if ($created > 0 && $finished > 0) {
					$tradeDurations[] = $finished - $created;
					$tradeIntervals[] = [$created, $finished];
				}
			}
			// Include open/pending positions: they cover time from created_at until simulation end.
			foreach ($openPositions as $pos) {
				$created = $pos->getCreatedAt();
				if ($created > 0) {
					$tradeIntervals[] = [$created, $simEndTime];
				}
			}
			$durationStats = $this->computeDurationStats($tradeDurations, $tradeIntervals, $simStartTime, $simEndTime);

			$this->printBacktestSummary(
				$pair->getTicker(),
				$pair->getTimeframe()->value,
				$exchangeName,
				$initialBalance,
				$finalBalance,
				$finishedCount,
				$openCount,
				$pendingCount,
				$liquidated,
				$openPositionsData,
				$durationStats,
				$maxDrawdown
			);
		}
	}

	/**
	 * Format duration in seconds to human-readable string (e.g. "5d 12h" or "120h 30m").
	 */
	private function formatDuration(int $seconds): string
	{
		if ($seconds < 0) {
			return '0';
		}
		$d = (int) floor($seconds / 86400);
		$h = (int) floor(($seconds % 86400) / 3600);
		$m = (int) floor(($seconds % 3600) / 60);
		$parts = [];
		if ($d > 0) {
			$parts[] = $d . 'd';
		}
		if ($h > 0 || $d > 0) {
			$parts[] = $h . 'h';
		}
		if ($m > 0 || $parts === []) {
			$parts[] = $m . 'm';
		}
		return implode(' ', $parts);
	}

	/**
	 * Draw a console box table. $headers = ['Col1','Col2'], $rows = [ ['a','b'], ['c','d'] ].
	 */
	private function drawTable(string $title, array $headers, array $rows): void
	{
		$colCount = count($headers);
		$widths = array_map('strlen', $headers);
		foreach ($rows as $row) {
			foreach (array_keys($headers) as $i) {
				$cell = isset($row[$i]) ? (string) $row[$i] : '';
				$widths[$i] = max($widths[$i], strlen($cell));
			}
		}
		$totalWidth = array_sum($widths) + 3 * $colCount;
		$titleLen = strlen($title);
		$pad = $totalWidth >= $titleLen ? (int) floor(($totalWidth - $titleLen) / 2) : 0;
		echo PHP_EOL;
		echo "\033[1m" . str_repeat(' ', max(0, $pad)) . $title . str_repeat(' ', max(0, $totalWidth - $titleLen - $pad)) . "\033[0m" . PHP_EOL;
		$top = '┌';
		foreach (array_keys($widths) as $idx) {
			$top .= str_repeat('─', $widths[$idx] + 2);
			$top .= ($idx < $colCount - 1) ? '┬' : '┐';
		}
		echo $top . PHP_EOL;
		$headerRow = '│';
		foreach (array_keys($headers) as $i) {
			$headerRow .= ' ' . str_pad($headers[$i], $widths[$i]) . ' │';
		}
		echo $headerRow . PHP_EOL;
		$mid = '├';
		foreach (array_keys($widths) as $idx) {
			$mid .= str_repeat('─', $widths[$idx] + 2);
			$mid .= ($idx < $colCount - 1) ? '┼' : '┤';
		}
		echo $mid . PHP_EOL;
		foreach ($rows as $row) {
			$line = '│';
			foreach (array_keys($headers) as $i) {
				$cell = isset($row[$i]) ? (string) $row[$i] : '';
				$line .= ' ' . str_pad($cell, $widths[$i]) . ' │';
			}
			echo $line . PHP_EOL;
		}
		$bot = '└';
		foreach (array_keys($widths) as $idx) {
			$bot .= str_repeat('─', $widths[$idx] + 2);
			$bot .= ($idx < $colCount - 1) ? '┴' : '┘';
		}
		echo $bot . PHP_EOL;
	}

	/**
	 * Compute trade duration statistics and time without open positions.
	 *
	 * @param int[] $durations Array of trade durations in seconds.
	 * @param array $intervals Array of [created_at, finished_at] pairs for finished trades.
	 * @param int $simStart Simulation start timestamp.
	 * @param int $simEnd Simulation end timestamp.
	 * @return array{shortest: int, longest: int, average: int, idle: int}
	 */
	private function computeDurationStats(array $durations, array $intervals, int $simStart, int $simEnd): array
	{
		$shortest = 0;
		$longest = 0;
		$average = 0;
		if (count($durations) > 0) {
			$shortest = min($durations);
			$longest = max($durations);
			$average = (int) round(array_sum($durations) / count($durations));
		}

		// Compute time without any open positions.
		// Merge overlapping intervals and sum the covered time.
		$totalSpan = max(0, $simEnd - $simStart);
		$coveredTime = 0;
		if (count($intervals) > 0) {
			// Sort by start time.
			usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);
			$merged = [$intervals[0]];
			for ($i = 1; $i < count($intervals); $i++) {
				$last = &$merged[count($merged) - 1];
				if ($intervals[$i][0] <= $last[1]) {
					$last[1] = max($last[1], $intervals[$i][1]);
				} else {
					$merged[] = $intervals[$i];
				}
			}
			unset($last);
			foreach ($merged as $m) {
				$start = max($m[0], $simStart);
				$end = min($m[1], $simEnd);
				if ($end > $start) {
					$coveredTime += $end - $start;
				}
			}
		}
		$idle = max(0, $totalSpan - $coveredTime);

		return [
			'shortest' => $shortest,
			'longest' => $longest,
			'average' => $average,
			'idle' => $idle,
		];
	}

	private function printBacktestSummary(
		string $ticker,
		string $timeframe,
		string $exchangeName,
		float $initialBalance,
		float $finalBalance,
		int $totalTrades,
		int $openCount,
		int $pendingCount,
		bool $liquidated = false,
		array $openPositionsData = [],
		array $durationStats = [],
		float $maxDrawdown = 0.0
	): void {
		$pnl = $finalBalance - $initialBalance;
		$pnlPercent = $initialBalance > 0 ? (($pnl / $initialBalance) * 100) : 0.0;
		$statusStr = $liquidated ? 'LIQUIDATED (simulation stopped)' : 'Completed';

		$summaryHeaders = ['Metric', 'Value'];
		$summaryRows = [
			['Pair', "$ticker $timeframe ($exchangeName)"],
			['Status', $statusStr],
			['Initial balance', number_format($initialBalance, 2) . ' USDT'],
			['Final balance', number_format($finalBalance, 2) . ' USDT'],
			['PnL', number_format($pnl, 2) . ' USDT (' . number_format($pnlPercent, 2) . '%)'],
			['Max drawdown', number_format($maxDrawdown, 2) . ' USDT'],
			['Trades (finished)', (string) $totalTrades],
			['Open', (string) $openCount],
			['Pending', (string) $pendingCount],
		];
		if (!empty($durationStats) && $totalTrades > 0) {
			$summaryRows[] = ['Shortest trade', $this->formatDuration($durationStats['shortest'])];
			$summaryRows[] = ['Longest trade', $this->formatDuration($durationStats['longest'])];
			$summaryRows[] = ['Average trade duration', $this->formatDuration($durationStats['average'])];
			$summaryRows[] = ['Time without positions', $this->formatDuration($durationStats['idle'])];
		}
		$this->drawTable('Backtest Summary', $summaryHeaders, $summaryRows);

		if ($openPositionsData !== []) {
			$posHeaders = ['Direction', 'Entry', 'Volume', 'Created', 'Time open', 'Unrealized PnL'];
			$posRows = [];
			foreach ($openPositionsData as $p) {
				$posRows[] = [
					$p['direction'],
					number_format($p['entry'], 4),
					number_format($p['volume'], 4),
					date('Y-m-d H:i', $p['created_at']),
					$this->formatDuration($p['time_hanging_sec']),
					number_format($p['unrealized_pnl'], 2) . ' USDT',
				];
			}
			$this->drawTable('Open / Pending positions at end', $posHeaders, $posRows);
		}
		echo PHP_EOL;
	}
}
