<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Backtest\BacktestFinancialResult;
use Izzy\Backtest\BacktestOpenPosition;
use Izzy\Backtest\BacktestResult;
use Izzy\Backtest\BacktestRiskRatios;
use Izzy\Backtest\BacktestTradeStats;
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
			Logger::getLogger()->setBacktestMode(true);
			$this->runBacktestLoop($pairsForBacktest);
		} finally {
			Logger::getLogger()->setBacktestMode(false);
			$this->database->dropTableIfExists('backtest_positions');
		}
	}

	private function runBacktestLoop(array $pairsForBacktest): void {
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
					foreach ($backtestExchange->getPendingLimitOrders($market) as $order) {
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
						$log->backtestProgress(" * TP HIT $ticker $dir @ " . number_format($tp, 4) . " PnL " . number_format($profit, 2) . " USDT -> balance " . number_format($balanceAfter, 2) . " USDT");
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

			$lastClose = $lastCandle !== null ? $lastCandle->getClosePrice() : 0.0;
			$simEndTime = $lastCandle !== null ? ((int) $lastCandle->getOpenTime() + $candleDuration - 1) : time();
			$simStartTime = !empty($candles) ? (int) $candles[0]->getOpenTime() : $simEndTime;
			$simDurationDays = max(0, $simEndTime - $simStartTime) / 86400;

			// Collect open/pending positions.
			$whereOpen = array_merge($marketWhere, [
				BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
			]);
			$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $whereOpen, '');
			$openPositionDtos = [];
			foreach ($openPositions as $pos) {
				$vol = $pos->getVolume()->getAmount();
				$entry = $pos->getAverageEntryPrice()->getAmount();
				$unrealizedPnl = $pos->getDirection()->isLong()
					? $vol * ($lastClose - $entry)
					: $vol * ($entry - $lastClose);
				$openPositionDtos[] = new BacktestOpenPosition(
					direction: $pos->getDirection()->value,
					entry: $entry,
					volume: $vol,
					createdAt: $pos->getCreatedAt(),
					unrealizedPnl: $unrealizedPnl,
					timeHangingSec: $simEndTime - $pos->getCreatedAt(),
				);
			}

			// Collect finished trade data: durations, intervals, per-trade PnL.
			$whereFinished = array_merge($marketWhere, [
				BacktestStoredPosition::FStatus => PositionStatusEnum::FINISHED->value,
			]);
			$finishedPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $whereFinished, BacktestStoredPosition::FCreatedAt . ' ASC');
			$tradeDurations = [];
			$tradePnls = [];
			$tradeIntervals = [];
			$wins = 0;
			$losses = 0;
			foreach ($finishedPositions as $pos) {
				$created = $pos->getCreatedAt();
				$finished = $pos->getFinishedAt();
				if ($created > 0 && $finished > 0) {
					$tradeDurations[] = $finished - $created;
					$tradeIntervals[] = [$created, $finished];
				}
				$tp = $pos->getTakeProfitPrice();
				if ($tp !== null) {
					$vol = $pos->getVolume()->getAmount();
					$entry = $pos->getAverageEntryPrice()->getAmount();
					$tpAmount = $tp->getAmount();
					$pnl = $pos->getDirection()->isLong()
						? $vol * ($tpAmount - $entry)
						: $vol * ($entry - $tpAmount);
					$tradePnls[] = $pnl;
					$pnl > 0 ? $wins++ : $losses++;
				}
			}
			// Include open/pending positions: they cover time from created_at until simulation end.
			foreach ($openPositions as $pos) {
				$created = $pos->getCreatedAt();
				if ($created > 0) {
					$tradeIntervals[] = [$created, $simEndTime];
				}
			}

			// Build DTO and print.
			$result = new BacktestResult(
				pair: $pair,
				simStartTime: $simStartTime,
				simEndTime: $simEndTime,
				financial: new BacktestFinancialResult(
					initialBalance: $initialBalance,
					finalBalance: $finalBalance,
					maxDrawdown: $maxDrawdown,
					liquidated: $liquidated,
				),
				trades: BacktestTradeStats::fromRawData(
					durations: $tradeDurations,
					intervals: $tradeIntervals,
					simStart: $simStartTime,
					simEnd: $simEndTime,
					finished: $finishedCount,
					open: $openCount,
					pending: $pendingCount,
					wins: $wins,
					losses: $losses,
				),
				risk: BacktestRiskRatios::fromTradePnls(
					tradePnls: $tradePnls,
					initialBalance: $initialBalance,
					totalTrades: $finishedCount,
					simDurationDays: $simDurationDays,
				),
				openPositions: $openPositionDtos,
			);
			echo $result;
		}
	}

}
