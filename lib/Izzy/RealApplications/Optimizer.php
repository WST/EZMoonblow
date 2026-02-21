<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Backtest\BacktestBalanceChart;
use Izzy\Backtest\BacktestDirectionStats;
use Izzy\Backtest\BacktestFinancialResult;
use Izzy\Backtest\BacktestOpenPosition;
use Izzy\Backtest\BacktestResult;
use Izzy\Backtest\BacktestResultRecord;
use Izzy\Backtest\BacktestRiskRatios;
use Izzy\Backtest\BacktestTradeStats;
use Izzy\Backtest\OptimizationSuggestionRecord;
use Izzy\Enums\BacktestModeEnum;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Configuration\StrategyConfiguration;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\Candle;
use Izzy\Financial\CandleRepository;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Financial\StrategyFactory;
use Izzy\System\Logger;

/**
 * Optimizer daemon.
 *
 * Periodically backtests traded pairs with randomly mutated strategy parameters.
 * When a mutation improves PnL, an optimization suggestion is saved to the database.
 */
class Optimizer extends ConsoleApplication
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
	 *
	 * Re-uses Backtester's tick simulation logic.
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

		// Load candles.
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

		// Set up backtest exchange and market with a unique table per run.
		$suffix = 'opt_' . uniqid();
		BacktestStoredPosition::setTableSuffix($suffix);
		$tableName = BacktestStoredPosition::getTableName();

		$this->database->dropTableIfExists($tableName);
		if (!$this->database->createTableLike($tableName, 'positions')) {
			$this->logger->error("Failed to create {$tableName} table.");
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

			$market->initializeConfiguredIndicators();
			$market->initializeStrategy();
			$market->initializeIndicators();

			$n = count($candles);
			$log = Logger::getLogger();
			$candleDuration = $pair->getTimeframe()->toSeconds();
			$liquidated = false;
			$lastCandle = null;
			$maxDrawdown = 0.0;
			$balanceSnapshots = [];

			$this->database->beginTransaction();

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

				$ticks = $this->generateTicks(
					$openPrice, $highPrice, $lowPrice, $closePrice,
					$candleTime, $candleDuration, $this->ticksPerCandle, $isBullish,
				);

				$sliceLastIdx = count($slice) - 1;
				$totalTicks = count($ticks);
				$runningHigh = $openPrice;
				$runningLow = $openPrice;
				$tickIdx = 0;

				foreach ($ticks as [$tickTime, $tickPrice]) {
					$runningHigh = max($runningHigh, $tickPrice);
					$runningLow = min($runningLow, $tickPrice);
					$volumeFraction = $totalTicks > 1 ? $tickIdx / ($totalTicks - 1) : 1.0;
					$partialCandle = new Candle(
						$candleTime, $openPrice, $runningHigh, $runningLow, $tickPrice,
						$candleVolume * $volumeFraction, $market,
					);
					$slice[$sliceLastIdx] = $partialCandle;
					$market->setCandles($slice);
					$tickIdx++;

					$backtestExchange->setSimulationTime($tickTime);
					$log->setBacktestSimulationTime($tickTime);
					$backtestExchange->setCurrentPriceForMarket($market, Money::from($tickPrice));

					$market->calculateIndicators();
					$market->processTrading();

					// Fill pending DCA limit orders.
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

					// TP/SL checks.
					$where = [
						BacktestStoredPosition::FExchangeName => $market->getExchangeName(),
						BacktestStoredPosition::FTicker => $market->getTicker(),
						BacktestStoredPosition::FMarketType => $market->getMarketType()->value,
						BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
					];
					$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $where, '');
					$closedPositionIds = [];

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
						$profit = $position->getUnrealizedPnL()->getAmount();
						if ($profit <= 0) {
							continue;
						}
						$position->markFinished($tickTime);
						$position->setFinishReason(PositionFinishReasonEnum::TAKE_PROFIT_MARKET);
						$position->save();
						$backtestExchange->creditBalance($profit);
						$backtestExchange->clearPendingLimitOrders($market);
						$closedPositionIds[] = spl_object_id($position);
					}

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
						$position->setCurrentPrice($slPrice);
						$pnl = $position->getUnrealizedPnL()->getAmount();
						$position->markFinished($tickTime);
						$position->setFinishReason(PositionFinishReasonEnum::STOP_LOSS_MARKET);
						$position->save();
						$backtestExchange->creditBalance($pnl);
						$backtestExchange->clearPendingLimitOrders($market);
						$closedPositionIds[] = spl_object_id($position);
						$strategy = $market->getStrategy();
						if ($strategy instanceof AbstractSingleEntryStrategy) {
							$strategy->notifyStopLoss($tickTime);
						}
					}

					// Liquidation check.
					$balance = $backtestExchange->getVirtualBalance()->getAmount();
					$unrealizedPnl = 0.0;
					foreach ($openPositions as $position) {
						if (in_array(spl_object_id($position), $closedPositionIds, true)) {
							continue;
						}
						$vol = $position->getVolume()->getAmount();
						$entryP = $position->getAverageEntryPrice()->getAmount();
						if ($position->getDirection()->isLong()) {
							$unrealizedPnl += $vol * ($tickPrice - $entryP);
						} else {
							$unrealizedPnl += $vol * ($entryP - $tickPrice);
						}
					}
					if ($unrealizedPnl < $maxDrawdown) {
						$maxDrawdown = $unrealizedPnl;
					}
					if ($balance + $unrealizedPnl <= 0) {
						$liquidated = true;
						break 2;
					}
				}

				$balanceSnapshots[] = [$candleTime, $backtestExchange->getVirtualBalance()->getAmount()];
			}

			// Collect results.
			$finalBalance = $liquidated ? 0.0 : $backtestExchange->getVirtualBalance()->getAmount();
			$marketWhere = [
				BacktestStoredPosition::FExchangeName => $exchangeName,
				BacktestStoredPosition::FTicker => $pair->getTicker(),
				BacktestStoredPosition::FMarketType => $pair->getMarketType()->value,
			];
			$table = BacktestStoredPosition::getTableName();
			$finishedCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::FINISHED->value]));
			$openCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::OPEN->value]));
			$pendingCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::PENDING->value]));

			$lastClose = $lastCandle !== null ? $lastCandle->getClosePrice() : 0.0;
			$firstOpen = !empty($candles) ? $candles[0]->getOpenPrice() : 0.0;
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

			// Collect finished trade data.
			$whereFinished = array_merge($marketWhere, [
				BacktestStoredPosition::FStatus => PositionStatusEnum::FINISHED->value,
			]);
			$finishedPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $whereFinished, BacktestStoredPosition::FCreatedAt . ' ASC');
			$tradeDurations = [];
			$tradePnls = [];
			$tradeIntervals = [];
			$wins = 0;
			$losses = 0;
			$breakevenLocks = 0;
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
				$finishReason = $pos->getFinishReason();
				$closePrice = null;
				if ($finishReason !== null && $finishReason->isTakeProfit()) {
					$closePrice = $pos->getTakeProfitPrice()?->getAmount();
				} elseif ($finishReason !== null && $finishReason->isStopLoss()) {
					$closePrice = $pos->getStopLossPrice()?->getAmount();
				} else {
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

					$isBL = false;
					if ($finishReason !== null && $finishReason->isStopLoss() && $entry > 0) {
						$slPrice = $pos->getStopLossPrice()?->getAmount();
						if ($slPrice !== null) {
							$diff = abs($slPrice - $entry) / $entry;
							$isBL = $diff < 0.001;
						}
					}

					if ($isBL) {
						$breakevenLocks++;
					} elseif ($pnl > 0) {
						$wins++;
					} else {
						$losses++;
					}

					if ($isLong) {
						if ($duration !== null) $longDurations[] = $duration;
						if ($finishReason !== null && $finishReason->isTakeProfit()) $longWins++;
						elseif ($isBL) $longBL++;
						else $longLosses++;
					} else {
						if ($duration !== null) $shortDurations[] = $duration;
						if ($finishReason !== null && $finishReason->isTakeProfit()) $shortWins++;
						elseif ($isBL) $shortBL++;
						else $shortLosses++;
					}
				}
			}

			foreach ($openPositions as $pos) {
				$created = $pos->getCreatedAt();
				if ($created > 0) {
					$tradeIntervals[] = [$created, $simEndTime];
				}
			}

			$result = new BacktestResult(
				pair: $backtestPair,
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
					breakevenLocks: $breakevenLocks,
				),
				risk: BacktestRiskRatios::fromTradePnls(
					tradePnls: $tradePnls,
					initialBalance: $initialBalance,
					totalTrades: $finishedCount,
					simDurationDays: $simDurationDays,
				),
				openPositions: $openPositionDtos,
				exchangeTicker: '',
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

			$chartPng = BacktestBalanceChart::generate(
				$balanceSnapshots,
				$simStartTime,
				$simEndTime,
				$candleDuration,
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


	/**
	 * Generate N ticks that linearly interpolate the intra-candle price path.
	 * Duplicated from Backtester to keep Optimizer self-contained.
	 *
	 * @return array<array{int, float}>
	 */
	private function generateTicks(
		float $open, float $high, float $low, float $close,
		int $candleTime, int $candleDuration, int $ticksPerCandle, bool $isBullish,
	): array {
		$waypoints = $isBullish
			? [$open, $low, $high, $close]
			: [$open, $high, $low, $close];

		if ($ticksPerCandle <= 4) {
			$seg = $candleDuration / 3;
			return [
				[$candleTime, $waypoints[0]],
				[$candleTime + (int)($seg), $waypoints[1]],
				[$candleTime + (int)($seg * 2), $waypoints[2]],
				[$candleTime + $candleDuration - 1, $waypoints[3]],
			];
		}

		$totalIntervals = $ticksPerCandle - 1;
		$base = intdiv($totalIntervals, 3);
		$remainder = $totalIntervals % 3;
		$segIntervals = [
			$base + ($remainder > 0 ? 1 : 0),
			$base + ($remainder > 1 ? 1 : 0),
			$base,
		];

		$ticks = [];
		$tickNumber = 0;
		for ($seg = 0; $seg < 3; $seg++) {
			$startPrice = $waypoints[$seg];
			$endPrice = $waypoints[$seg + 1];
			$n = $segIntervals[$seg];
			for ($j = 0; $j < $n; $j++) {
				$fraction = $n > 0 ? $j / $n : 0.0;
				$price = $startPrice + ($endPrice - $startPrice) * $fraction;
				$time = $candleTime + (int)(($tickNumber / $totalIntervals) * ($candleDuration - 1));
				$ticks[] = [$time, $price];
				$tickNumber++;
			}
		}
		$ticks[] = [$candleTime + $candleDuration - 1, $waypoints[3]];

		return $ticks;
	}
}
