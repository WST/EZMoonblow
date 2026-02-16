<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Backtest\BacktestDirectionStats;
use Izzy\Backtest\BacktestEventWriter;
use Izzy\Backtest\BacktestFinancialResult;
use Izzy\Backtest\BacktestOpenPosition;
use Izzy\Backtest\BacktestResult;
use Izzy\Backtest\BacktestRiskRatios;
use Izzy\Backtest\BacktestTradeStats;
use Izzy\Enums\MarginModeEnum;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\Candle;
use Izzy\Financial\CandleRepository;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Financial\StrategyFactory;
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
	 */
	public function runWebBacktest(string $sessionId, array $config): void {
		$eventsFile = $this->getEventFilePath($sessionId);
		$writer = new BacktestEventWriter($eventsFile);

		try {
			$exchanges = $this->configuration->connectExchanges($this);

			$timeframe = TimeFrameEnum::from($config['timeframe']);
			$marketType = MarketTypeEnum::from($config['marketType']);
			$pair = new Pair($config['pair'], $timeframe, $config['exchangeName'], $marketType);
			$pair->setStrategyName($config['strategy']);
			$pair->setStrategyParams($config['params'] ?? []);
			$pair->setBacktestDays($config['days']);
			$pair->setBacktestInitialBalance($config['initialBalance']);
			$pair->setLeverage($config['leverage'] ?? null);

			// Find the real exchange driver for instrument info.
			$realExchange = $exchanges[$config['exchangeName']] ?? null;

			$this->database->dropTableIfExists('backtest_positions');
			if (!$this->database->createTableLike('backtest_positions', 'positions')) {
				$writer->writeError('Failed to create backtest_positions table.');
				$writer->writeDone();
				return;
			}

			Logger::getLogger()->setBacktestMode(true);
			$this->runBacktestLoop(
				[['pair' => $pair, 'exchange' => $realExchange]],
				$writer,
			);
		} catch (\Throwable $e) {
			$writer->writeError($e->getMessage());
		} finally {
			Logger::getLogger()->setBacktestMode(false);
			$this->database->dropTableIfExists('backtest_positions');
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

	/**
	 * @param array $pairsForBacktest Array of ['pair' => Pair, 'exchange' => IExchangeDriver|null].
	 * @param BacktestEventWriter|null $writer Optional event writer for web UI streaming.
	 */
	private function runBacktestLoop(array $pairsForBacktest, ?BacktestEventWriter $writer = null): void {
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

			// Configure the virtual exchange to match pair/strategy settings.
			if ($pair->getLeverage() !== null) {
				$backtestExchange->setBacktestLeverage($pair->getLeverage());
			}
			$strategyParams = $pair->getStrategyParams();
			if (filter_var($strategyParams['useIsolatedMargin'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
				$backtestExchange->setBacktestMarginMode(MarginModeEnum::ISOLATED);
			}

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

			// Fetch real instrument parameters (tick size, qty step) from the
			// exchange so that the backtest simulation uses realistic values
			// instead of hardcoded defaults.
			$realExchange = $entry['exchange'];
			try {
				$backtestExchange->setTickSize($market, $realExchange->getTickSize($market));
				$backtestExchange->setQtyStep($market, $realExchange->getQtyStep($market));
			} catch (\Throwable $e) {
				$this->logger->warning("Could not fetch instrument info from {$exchangeName}: " . $e->getMessage());
			}

			$market->initializeConfiguredIndicators();
			$market->initializeStrategy();
			$market->initializeIndicators();
			$n = count($candles);
			$ticker = $pair->getTicker();
			$log = Logger::getLogger();
			$log->backtestProgress("$ticker: $n candles, balance " . number_format($initialBalance, 2) . " USDT");
			$progressStep = max(1, (int) ($n / 20));

			// Emit init event for the web UI.
			if ($writer !== null) {
				$writer->writeInit(
					pair: $ticker,
					timeframe: $pair->getTimeframe()->value,
					strategy: $pair->getStrategyName() ?? '',
					params: $pair->getStrategyParams(),
					initialBalance: $initialBalance,
					totalCandles: $n,
					leverage: $pair->getLeverage() ?? 1,
				);
			}
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
			 *   2. Recalculate indicators (current candle is a partial snapshot —
			 *      only reflects OHLC state at this tick, not the final values)
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
				$currentCandle = $candles[$i];
				$lastCandle = $currentCandle;
				$candleTime = (int) $currentCandle->getOpenTime();

				$openPrice = $currentCandle->getOpenPrice();
				$highPrice = $currentCandle->getHighPrice();
				$lowPrice = $currentCandle->getLowPrice();
				$closePrice = $currentCandle->getClosePrice();
				$candleVolume = $currentCandle->getVolume();
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

				/*
				 * PARTIAL CANDLE SNAPSHOTS — lookahead bias prevention.
				 *
				 * Without this, the current candle in the slice already has its
				 * final high/low/close at every tick, so indicators (EMA, RSI, etc.)
				 * would "see the future" — e.g. RSI computed from the final close
				 * of a candle that hasn't actually closed yet.
				 *
				 * We create 4 progressive snapshots that reflect how the candle
				 * looks at each phase of its formation:
				 *
				 *   Bullish (close >= open): open → low → high → close
				 *     Phase 0 (open):  O=open  H=open  L=open  C=open  — just opened.
				 *     Phase 1 (low):   O=open  H=open  L=low   C=low   — dipped to low.
				 *     Phase 2 (high):  O=open  H=high  L=low   C=high  — rallied to high.
				 *     Phase 3 (close): O=open  H=high  L=low   C=close — fully formed.
				 *
				 *   Bearish (close < open): open → high → low → close
				 *     Phase 0 (open):  O=open  H=open  L=open  C=open  — just opened.
				 *     Phase 1 (high):  O=open  H=high  L=open  C=high  — rallied to high.
				 *     Phase 2 (low):   O=open  H=high  L=low   C=low   — dropped to low.
				 *     Phase 3 (close): O=open  H=high  L=low   C=close — fully formed.
				 *
				 * Before each tick, the last candle in the slice is replaced with
				 * the corresponding partial snapshot, then indicators are recalculated.
				 * Volume is distributed proportionally: 0%, 25%, 75%, 100%.
				 */
				$partialCandles = $isBullish
					? [
						new Candle($candleTime, $openPrice, $openPrice, $openPrice, $openPrice, 0.0, $market),
						new Candle($candleTime, $openPrice, $openPrice, $lowPrice,  $lowPrice,  $candleVolume * 0.25, $market),
						new Candle($candleTime, $openPrice, $highPrice, $lowPrice,  $highPrice, $candleVolume * 0.75, $market),
						new Candle($candleTime, $openPrice, $highPrice, $lowPrice,  $closePrice, $candleVolume, $market),
					]
					: [
						new Candle($candleTime, $openPrice, $openPrice, $openPrice, $openPrice, 0.0, $market),
						new Candle($candleTime, $openPrice, $highPrice, $openPrice, $highPrice, $candleVolume * 0.25, $market),
						new Candle($candleTime, $openPrice, $highPrice, $lowPrice,  $lowPrice,  $candleVolume * 0.75, $market),
						new Candle($candleTime, $openPrice, $highPrice, $lowPrice,  $closePrice, $candleVolume, $market),
					];

				$sliceLastIdx = count($slice) - 1;
				$tickIdx = 0;
				foreach ($ticks as [$tickTime, $tickPrice]) {
					// Replace the last candle with a partial snapshot so that
					// indicators only see the candle's state at this tick phase.
					$slice[$sliceLastIdx] = $partialCandles[$tickIdx];
					$market->setCandles($slice);
					$tickIdx++;

					// --- 1. Set simulation time and price ---
					$backtestExchange->setSimulationTime($tickTime);
					$log->setBacktestSimulationTime($tickTime);
					$backtestExchange->setCurrentPriceForMarket($market, Money::from($tickPrice));

					// --- 2. Recalculate indicators ---
					$market->calculateIndicators();

					// --- 3. processTrading: entry signals / position updates ---
					// Snapshot position state before processTrading for event detection.
					$preVolume = null;
					$preSL = null;
					$posCountBefore = 0;
					if ($writer !== null) {
						$posCountBefore = $this->database->countRows(
							BacktestStoredPosition::getTableName(),
							[
								BacktestStoredPosition::FExchangeName => $market->getExchangeName(),
								BacktestStoredPosition::FTicker => $market->getTicker(),
								BacktestStoredPosition::FMarketType => $market->getMarketType()->value,
								BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
							]
						);
						$existingPos = $market->getStoredPosition();
						if ($existingPos !== false) {
							$preVolume = $existingPos->getVolume()->getAmount();
							$preSL = $existingPos->getStopLossPrice()?->getAmount();
						}
					}
					$market->processTrading();
					// Detect events that occurred during processTrading.
					if ($writer !== null) {
						$posCountAfter = $this->database->countRows(
							BacktestStoredPosition::getTableName(),
							[
								BacktestStoredPosition::FExchangeName => $market->getExchangeName(),
								BacktestStoredPosition::FTicker => $market->getTicker(),
								BacktestStoredPosition::FMarketType => $market->getMarketType()->value,
								BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
							]
						);
						// New position opened.
						if ($posCountAfter > $posCountBefore) {
							$newPos = $market->getStoredPosition();
							if ($newPos !== false) {
								$writer->writePositionOpen(
									$newPos->getDirection()->value,
									$newPos->getAverageEntryPrice()->getAmount(),
									$newPos->getVolume()->getAmount(),
									$tickTime,
								);
							}
						}
						// DCA fill: volume increased while the same position stays open.
						if ($preVolume !== null) {
							$postPos = $market->getStoredPosition();
							if ($postPos !== false) {
								$postVolume = $postPos->getVolume()->getAmount();
								$postSL = $postPos->getStopLossPrice()?->getAmount();
								if ($postVolume > $preVolume) {
									$writer->writeDCAFill(
										$postPos->getDirection()->value,
										$tickPrice,
										$postVolume - $preVolume,
										$postPos->getAverageEntryPrice()->getAmount(),
										$postVolume,
										$tickTime,
									);
								}
								// Breakeven Lock: volume decreased and SL moved to near entry.
								if ($postVolume < $preVolume && $postSL !== null && $postSL !== $preSL) {
									$closedVolume = $preVolume - $postVolume;
									$entry = $postPos->getAverageEntryPrice()->getAmount();
									$lockedProfit = $postPos->getDirection()->isLong()
										? $closedVolume * ($postSL - $entry)
										: $closedVolume * ($entry - $postSL);
									$writer->writeBreakevenLock($closedVolume, $postSL, abs($lockedProfit), $tickTime);
									$writer->writeBalance($backtestExchange->getVirtualBalance()->getAmount());
								}
							}
						}
					}

					// --- 4. Fill pending DCA limit orders ---
					foreach ($backtestExchange->getPendingLimitOrders($market) as $order) {
						$orderPrice = $order['price'];
						$filled = $order['direction']->isLong()
							? ($tickPrice <= $orderPrice)
							: ($tickPrice >= $orderPrice);
						if ($filled) {
							$backtestExchange->addToPosition($market, $order['volumeBase'], $order['price']);
							$backtestExchange->removePendingLimitOrder($market, $order['orderId']);
							if ($writer !== null) {
								$filledPos = $market->getStoredPosition();
								if ($filledPos !== false) {
									$writer->writeDCAFill(
										$order['direction']->value,
										$order['price'],
										$order['volumeBase'],
										$filledPos->getAverageEntryPrice()->getAmount(),
										$filledPos->getVolume()->getAmount(),
										$tickTime,
									);
								}
							}
						}
					}

					// Load open/pending positions from DB once per tick.
					// TP/SL/Liquidation checks below filter this array in-memory
					// instead of re-querying the database (saves ~2 SELECTs per tick).
					$where = [
						BacktestStoredPosition::FExchangeName => $market->getExchangeName(),
						BacktestStoredPosition::FTicker => $market->getTicker(),
						BacktestStoredPosition::FMarketType => $market->getMarketType()->value,
						BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
					];
					$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $where, '');

					// Track which positions get closed by TP/SL so we can
					// exclude them in subsequent checks without re-querying DB.
					$closedPositionIds = [];

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
						$position->setFinishReason(PositionFinishReasonEnum::TAKE_PROFIT_MARKET);
						$position->save();
						$backtestExchange->creditBalance($profit);
						$backtestExchange->clearPendingLimitOrders($market);
						$closedPositionIds[] = spl_object_id($position);
						$balanceAfter = $backtestExchange->getVirtualBalance()->getAmount();
						$dir = $position->getDirection()->value;
						$log->backtestProgress(" * TP HIT $ticker $dir @ " . number_format($tp, 4) . " PnL " . number_format($profit, 2) . " USDT → balance " . number_format($balanceAfter, 2) . " USDT");
						if ($writer !== null) {
							$writer->writePositionClose($tp, $profit, 'TP', $tickTime);
							$writer->writeBalance($balanceAfter);
						}
					}

					// --- 5.5. Check Stop-Loss hits ---
					// Use the same in-memory array, skipping positions already closed by TP.
					foreach ($openPositions as $position) {
						if (in_array(spl_object_id($position), $closedPositionIds, true)) {
							continue;
						}
						$slPrice = $position->getStopLossPrice();
						if ($slPrice === null) {
							continue;
						}
						$sl = $slPrice->getAmount();
						$hit = $position->getDirection()->isLong()
							? ($tickPrice <= $sl)
							: ($tickPrice >= $sl);
						if (!$hit) {
							continue;
						}
						// Close at SL price.
						$position->setCurrentPrice($slPrice);
						$pnl = $position->getUnrealizedPnL()->getAmount();
						$position->markFinished($tickTime);
						$position->setFinishReason(PositionFinishReasonEnum::STOP_LOSS_MARKET);
						$position->save();
						$backtestExchange->creditBalance($pnl);
						$backtestExchange->clearPendingLimitOrders($market);
						$closedPositionIds[] = spl_object_id($position);
						$dir = $position->getDirection()->value;
						$balanceAfter = $backtestExchange->getVirtualBalance()->getAmount();
						$log->backtestProgress(" * SL HIT $ticker $dir @ " . number_format($sl, 4) . " PnL " . number_format($pnl, 2) . " USDT → balance " . number_format($balanceAfter, 2) . " USDT");
						if ($writer !== null) {
							$writer->writePositionClose($sl, $pnl, 'SL', $tickTime);
							$writer->writeBalance($balanceAfter);
						}
					}

					// --- 6. Liquidation check ---
					// Use the same in-memory array, skipping closed positions.
					$balance = $backtestExchange->getVirtualBalance()->getAmount();
					$unrealizedPnl = 0.0;
					foreach ($openPositions as $position) {
						if (in_array(spl_object_id($position), $closedPositionIds, true)) {
							continue;
						}
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
						if ($writer !== null) {
							$writer->writePositionClose($tickPrice, $balance + $unrealizedPnl, 'LIQUIDATION', $tickTime);
							$writer->writeBalance(0.0);
						}
						break 2; // Exit both tick and candle loops.
					}
				}

				// After all 4 ticks of a candle: emit candle + progress events.
				if ($writer !== null) {
					$indicators = $market->getIndicatorValues();
					$writer->writeCandle(
						$candleTime,
						$openPrice,
						$highPrice,
						$lowPrice,
						$closePrice,
						$candleVolume,
						$indicators,
					);
					// Emit progress every ~50 candles.
					if ($i % 50 === 0 || $i === $n - 1) {
						$writer->writeProgress($i + 1, $n);
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
			$firstOpen = !empty($candles) ? $candles[0]->getOpenPrice() : 0.0;
			$simEndTime = $lastCandle !== null ? ((int) $lastCandle->getOpenTime() + $candleDuration - 1) : time();
			$simStartTime = !empty($candles) ? (int) $candles[0]->getOpenTime() : $simEndTime;

			// Resolve exchange-specific ticker (e.g., "1000PEPEUSDT" on Bybit).
			$exchangeClass = "\\Izzy\\Exchanges\\$exchangeName\\$exchangeName";
			$exchangeTicker = class_exists($exchangeClass) && method_exists($exchangeClass, 'pairToTicker')
				? $exchangeClass::pairToTicker($pair)
				: '';
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

			// Per-direction tracking.
			$longDurations = [];
			$longWins = 0;
			$longLosses = 0;
			$longBL = 0;
			$shortDurations = [];
			$shortWins = 0;
			$shortLosses = 0;
			$shortBL = 0;

			foreach ($finishedPositions as $pos) {
				$created = $pos->getCreatedAt();
				$finished = $pos->getFinishedAt();
				$duration = ($created > 0 && $finished > 0) ? $finished - $created : null;
				if ($duration !== null) {
					$tradeDurations[] = $duration;
					$tradeIntervals[] = [$created, $finished];
				}
				// Determine close price based on how the position was closed.
				$finishReason = $pos->getFinishReason();
				$closePrice = null;
				if ($finishReason !== null && $finishReason->isTakeProfit()) {
					$closePrice = $pos->getTakeProfitPrice()?->getAmount();
				} elseif ($finishReason !== null && $finishReason->isStopLoss()) {
					$closePrice = $pos->getStopLossPrice()?->getAmount();
				} else {
					// Fallback for positions without FinishReason (legacy TP-only path).
					$closePrice = $pos->getTakeProfitPrice()?->getAmount();
				}
				if ($closePrice !== null) {
					$vol = $pos->getVolume()->getAmount();
					$entry = $pos->getAverageEntryPrice()->getAmount();
					$isLong = $pos->getDirection()->isLong();
					$pnl = $isLong
						? $vol * ($closePrice - $entry)
						: $vol * ($entry - $closePrice);
					$tradePnls[] = $pnl;
					$pnl > 0 ? $wins++ : $losses++;

					// Detect Breakeven Lock: SL-closed position where SL is
					// very close to entry (within 0.1%), meaning BL was executed.
					$isBL = false;
					if ($finishReason !== null && $finishReason->isStopLoss() && $entry > 0) {
						$slPrice = $pos->getStopLossPrice()?->getAmount();
						if ($slPrice !== null) {
							$diff = abs($slPrice - $entry) / $entry;
							$isBL = $diff < 0.001;
						}
					}

					if ($isLong) {
						if ($duration !== null) {
							$longDurations[] = $duration;
						}
						if ($finishReason !== null && $finishReason->isTakeProfit()) {
							$longWins++;
						} elseif ($isBL) {
							$longBL++;
						} else {
							$longLosses++;
						}
					} else {
						if ($duration !== null) {
							$shortDurations[] = $duration;
						}
						if ($finishReason !== null && $finishReason->isTakeProfit()) {
							$shortWins++;
						} elseif ($isBL) {
							$shortBL++;
						} else {
							$shortLosses++;
						}
					}
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
					coinPriceStart: $firstOpen,
					coinPriceEnd: $lastClose,
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
				exchangeTicker: $exchangeTicker,
				longStats: BacktestDirectionStats::fromRawData(
					label: 'Longs',
					durations: $longDurations,
					wins: $longWins,
					losses: $longLosses,
					breakevenLocks: $longBL,
				),
				shortStats: BacktestDirectionStats::fromRawData(
					label: 'Shorts',
					durations: $shortDurations,
					wins: $shortWins,
					losses: $shortLosses,
					breakevenLocks: $shortBL,
				),
			);
			echo $result;

			// Emit the result summary for the web UI.
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
					'winRate' => round($winRate, 1),
					'sharpe' => $result->risk?->sharpe,
					'sortino' => $result->risk?->sortino,
					'coinPriceStart' => $result->financial->coinPriceStart,
					'coinPriceEnd' => $result->financial->coinPriceEnd,
				]);
			}
		}

		// Print DB query stats to help profile and optimize SQL usage.
		$queryStats = Logger::getLogger()->getQueryStats();
		$totalSec = number_format($queryStats['totalMs'] / 1000, 2);
		echo "\n\033[90mDB stats: {$queryStats['count']} queries, {$totalSec}s total query time\033[0m\n";
	}

}
