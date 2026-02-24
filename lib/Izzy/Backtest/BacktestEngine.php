<?php

namespace Izzy\Backtest;

use Closure;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\Candle;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Interfaces\IMarket;
use Izzy\System\Database\Database;
use Izzy\System\Logger;

/**
 * Unified backtest simulation engine.
 *
 * Encapsulates tick generation, the simulation loop (processTrading, DCA fills,
 * TP/SL checks, liquidation), and result collection. Used by Backtester,
 * Optimizer, and Screener to avoid code duplication.
 */
class BacktestEngine
{
	public function __construct(
		private Database $database,
		private Logger $logger,
	) {
	}

	/**
	 * Generate N ticks that linearly interpolate the intra-candle price path.
	 *
	 * The path has 4 waypoints connected by 3 segments:
	 *   Bullish: open -> low  -> high -> close
	 *   Bearish: open -> high -> low  -> close
	 *
	 * @return array<array{int, float}>
	 */
	public static function generateTicks(
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

	/**
	 * Run the tick simulation loop over the given candles.
	 *
	 * @param Pair $pair Trading pair being backtested.
	 * @param array $candles Array of Candle objects to simulate over.
	 * @param BacktestExchange $backtestExchange Virtual exchange for the simulation.
	 * @param IMarket $market Market with strategy and indicators initialized.
	 * @param int $ticksPerCandle Number of synthetic ticks per candle.
	 * @param BacktestEventWriter|null $writer Optional event writer for web UI streaming.
	 * @param string|null $sessionId Web backtest session ID (for abort support via stop file).
	 * @param Closure|null $shouldStop Optional callback returning true to abort simulation.
	 */
	public function runSimulation(
		Pair $pair,
		array $candles,
		BacktestExchange $backtestExchange,
		IMarket $market,
		int $ticksPerCandle,
		?BacktestEventWriter $writer = null,
		?string $sessionId = null,
		?Closure $shouldStop = null,
	): BacktestSimulationState {
		$n = count($candles);
		$ticker = $pair->getTicker();
		$log = Logger::getLogger();
		$initialBalance = $backtestExchange->getVirtualBalance()->getAmount();

		if ($writer !== null) {
			$writer->writeInit(
				pair: $ticker,
				timeframe: $pair->getTimeframe()->value,
				strategy: $pair->getStrategyName() ?? '',
				params: $pair->getStrategyParams(),
				initialBalance: $initialBalance,
				totalCandles: $n,
			);
		}

		$liquidated = false;
		$aborted = false;
		$lastCandle = null;
		$peakEquity = $initialBalance;
		$maxDrawdown = 0.0;
		$simStartTime = !empty($candles) ? (int) $candles[0]->getOpenTime() : time();
		$peakEquityTime = $simStartTime;
		$longestLosingDuration = 0;
		$candleDuration = $pair->getTimeframe()->toSeconds();
		$balanceSnapshots = [];
		$market->setCandles([]);

		$this->database->resetQueryTimer();
		$indicatorTimeNs = 0;
		$simWallStart = hrtime(true);

		for ($i = 0; $i < $n; $i++) {
			if ($shouldStop !== null && $shouldStop()) {
				$aborted = true;
				break;
			}

			$currentCandle = $candles[$i];
			$lastCandle = $currentCandle;
			$market->appendCandle($currentCandle);
			$candleTime = (int) $currentCandle->getOpenTime();

			$openPrice = $currentCandle->getOpenPrice();
			$highPrice = $currentCandle->getHighPrice();
			$lowPrice = $currentCandle->getLowPrice();
			$closePrice = $currentCandle->getClosePrice();
			$candleVolume = $currentCandle->getVolume();
			$isBullish = $closePrice >= $openPrice;

			$ticks = self::generateTicks(
				$openPrice, $highPrice, $lowPrice, $closePrice,
				$candleTime, $candleDuration, $ticksPerCandle, $isBullish,
			);

			$totalTicks = count($ticks);
			$runningHigh = $openPrice;
			$runningLow = $openPrice;
			$unrealizedPnl = 0.0;
			$tickIdx = 0;

			foreach ($ticks as [$tickTime, $tickPrice]) {
				$runningHigh = max($runningHigh, $tickPrice);
				$runningLow = min($runningLow, $tickPrice);
				$volumeFraction = $totalTicks > 1 ? $tickIdx / ($totalTicks - 1) : 1.0;
				$partialCandle = new Candle(
					$candleTime, $openPrice, $runningHigh, $runningLow, $tickPrice,
					$candleVolume * $volumeFraction, $market,
				);
				$market->replaceLastCandle($partialCandle);
				$tickIdx++;

				$backtestExchange->setSimulationTime($tickTime);
				$log->setBacktestSimulationTime($tickTime);
				$backtestExchange->setCurrentPriceForMarket($market, Money::from($tickPrice));

				$indT0 = hrtime(true);
				$market->calculateIndicators();
				$indicatorTimeNs += hrtime(true) - $indT0;

				$market->warmPositionCache();

				$preSnap = [];
				if ($writer !== null) {
					foreach ([PositionDirectionEnum::LONG, PositionDirectionEnum::SHORT] as $snapDir) {
						$pos = $market->getStoredPositionByDirection($snapDir);
						if ($pos !== false) {
							$preSnap[$snapDir->value] = [
								'volume' => $pos->getVolume()->getAmount(),
								'sl' => $pos->getStopLossPrice()?->getAmount(),
							];
						}
					}
				}

				$market->processTrading();
				$market->invalidatePositionCache();
				$market->warmPositionCache();

				if ($writer !== null) {
					foreach ([PositionDirectionEnum::LONG, PositionDirectionEnum::SHORT] as $snapDir) {
						$postPos = $market->getStoredPositionByDirection($snapDir);
						$had = isset($preSnap[$snapDir->value]);
						if ($postPos === false) {
							continue;
						}
						$postVolume = $postPos->getVolume()->getAmount();
						$postSL = $postPos->getStopLossPrice()?->getAmount();

						if (!$had) {
							$writer->writePositionOpen(
								$postPos->getDirection()->value,
								$postPos->getAverageEntryPrice()->getAmount(),
								$postVolume,
								$candleTime,
							);
							continue;
						}

						$preVolume = $preSnap[$snapDir->value]['volume'];
						$preSL = $preSnap[$snapDir->value]['sl'];

						if ($postVolume > $preVolume) {
							$writer->writeDCAFill(
								$postPos->getDirection()->value,
								$tickPrice,
								$postVolume - $preVolume,
								$postPos->getAverageEntryPrice()->getAmount(),
								$postVolume,
								$candleTime,
							);
						}
						if ($postVolume < $preVolume) {
							$closedVolume = $preVolume - $postVolume;
							$entry = $postPos->getAverageEntryPrice()->getAmount();

							if ($postSL !== null && $postSL !== $preSL) {
								$lockedProfit = $postPos->getDirection()->isLong()
									? $closedVolume * ($postSL - $entry)
									: $closedVolume * ($entry - $postSL);
								$writer->writeBreakevenLock($closedVolume, $postSL, abs($lockedProfit), $candleTime);
							} else {
								$closePrice = $tickPrice;
								$lockedProfit = $postPos->getDirection()->isLong()
									? $closedVolume * ($closePrice - $entry)
									: $closedVolume * ($entry - $closePrice);
								$writer->writePartialClose($closedVolume, $closePrice, abs($lockedProfit), $candleTime);
							}
							$writer->writeBalance($backtestExchange->getVirtualBalance()->getAmount());
						}
					}
				}

				foreach ($backtestExchange->getPendingLimitOrders($market) as $order) {
					$orderPrice = $order['price'];
					$filled = $order['direction']->isLong()
						? ($tickPrice <= $orderPrice)
						: ($tickPrice >= $orderPrice);
					if ($filled) {
						$backtestExchange->addToPosition($market, $order['volumeBase'], $order['price'], $order['direction']);
						$backtestExchange->removePendingLimitOrder($market, $order['orderId']);
						if ($writer !== null) {
							$filledPos = $market->getStoredPositionByDirection($order['direction']);
							if ($filledPos !== false) {
								$writer->writeDCAFill(
									$order['direction']->value,
									$order['price'],
									$order['volumeBase'],
									$filledPos->getAverageEntryPrice()->getAmount(),
									$filledPos->getVolume()->getAmount(),
									$candleTime,
								);
							}
						}
					}
				}

				$openPositions = $market->getCachedPositions();
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
					$profitMoney = $position->getUnrealizedPnL();
					$profit = $profitMoney->getAmount();
					if ($profit <= 0) {
						continue;
					}
					$position->markFinished($tickTime);
					$position->setFinishReason(PositionFinishReasonEnum::TAKE_PROFIT_MARKET);
					$position->save();
					$backtestExchange->creditBalance($profit);
					$backtestExchange->deductTradeFee($position->getVolume()->getAmount() * $tp);
					$backtestExchange->clearPendingLimitOrdersByDirection($market, $position->getDirection());
					$closedPositionIds[] = spl_object_id($position);
					$balanceAfter = $backtestExchange->getVirtualBalance()->getAmount();
					$dir = $position->getDirection()->value;
					$log->backtestProgress(" * TP HIT $ticker $dir @ " . number_format($tp, 4) . " PnL " . number_format($profit, 2) . " USDT → balance " . number_format($balanceAfter, 2) . " USDT");
					if ($writer !== null) {
						$writer->writePositionClose($tp, $profit, 'TP', $candleTime, $dir);
						$writer->writeBalance($balanceAfter);
					}
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
					$backtestExchange->deductTradeFee($position->getVolume()->getAmount() * $sl);
					$backtestExchange->clearPendingLimitOrdersByDirection($market, $position->getDirection());
					$closedPositionIds[] = spl_object_id($position);
					$dir = $position->getDirection()->value;
					$balanceAfter = $backtestExchange->getVirtualBalance()->getAmount();
					$log->backtestProgress(" * SL HIT $ticker $dir @ " . number_format($sl, 4) . " PnL " . number_format($pnl, 2) . " USDT → balance " . number_format($balanceAfter, 2) . " USDT");
					if ($writer !== null) {
						$writer->writePositionClose($sl, $pnl, 'SL', $candleTime, $dir);
						$writer->writeBalance($balanceAfter);
					}
					$strategy = $market->getStrategy();
					if ($strategy instanceof AbstractSingleEntryStrategy) {
						$strategy->notifyStopLoss($tickTime);
					}
				}

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
				$equity = $balance + $unrealizedPnl;
				if ($equity >= $peakEquity) {
					$losingDuration = $tickTime - $peakEquityTime;
					if ($losingDuration > $longestLosingDuration) {
						$longestLosingDuration = $losingDuration;
					}
					$peakEquity = $equity;
					$peakEquityTime = $tickTime;
				}
				$drawdown = $equity - $peakEquity;
				if ($drawdown < $maxDrawdown) {
					$maxDrawdown = $drawdown;
				}
				if ($balance + $unrealizedPnl <= 0) {
					$liquidated = true;
					$dateStr = date('Y-m-d H:i', $tickTime);
					$log->backtestProgress("  LIQUIDATION at candle " . ($i + 1) . "/$n ($dateStr): balance " . number_format($balance, 2) . " USDT + unrealized PnL " . number_format($unrealizedPnl, 2) . " USDT <= 0");
					$this->logger->warning("Backtest stopped: liquidated at candle " . ($i + 1) . " $dateStr.");
					if ($writer !== null) {
						$writer->writePositionClose($tickPrice, $balance + $unrealizedPnl, 'LIQUIDATION', $candleTime);
						$writer->writeBalance(0.0);
					}
					break 2;
				}
			}

			$market->invalidatePositionCache();
			$market->replaceLastCandle($currentCandle);

			$equity = $backtestExchange->getVirtualBalance()->getAmount() + $unrealizedPnl;
			$balanceSnapshots[] = [$candleTime, $equity];

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
				$writer->writeBalance($equity, $backtestExchange->getVirtualBalance()->getAmount());
				if ($i % 50 === 0 || $i === $n - 1) {
					$writer->writeProgress($i + 1, $n);
				}
				if ($sessionId !== null && $i % 50 === 0) {
					$stopFile = sys_get_temp_dir() . "/backtest-{$sessionId}-stop";
					if (file_exists($stopFile)) {
						@unlink($stopFile);
						$this->logger->info("Backtest aborted by user at candle " . ($i + 1) . "/$n.");
						$writer->writeError('Aborted by user.');
						$aborted = true;
						break;
					}
				}
			}
		}

		return new BacktestSimulationState(
			liquidated: $liquidated,
			maxDrawdown: $maxDrawdown,
			peakEquity: $peakEquity,
			peakEquityTime: $peakEquityTime,
			longestLosingDuration: $longestLosingDuration,
			balanceSnapshots: $balanceSnapshots,
			lastCandle: $lastCandle,
			candleDuration: $candleDuration,
			totalFees: $backtestExchange->getTotalFeesPaid(),
			indicatorTimeNs: $indicatorTimeNs,
			aborted: $aborted,
		);
	}

	/**
	 * Collect backtest statistics from positions and build the final BacktestResult.
	 *
	 * @param BacktestSimulationState $state Simulation state from runSimulation().
	 * @param Pair $pair Original pair (used for exchange ticker resolution).
	 * @param Pair $backtestPair Pair used during simulation (with strategy params).
	 * @param BacktestExchange $backtestExchange Virtual exchange (for final balance).
	 * @param string $exchangeName Exchange name string.
	 * @param float $initialBalance Starting balance.
	 * @param array $candles Candle array (for first candle open price).
	 */
	public function collectResults(
		BacktestSimulationState $state,
		Pair $pair,
		Pair $backtestPair,
		BacktestExchange $backtestExchange,
		string $exchangeName,
		float $initialBalance,
		array $candles,
	): BacktestResult {
		$finalBalance = $state->liquidated ? 0.0 : $backtestExchange->getVirtualBalance()->getAmount();
		$marketWhere = [
			BacktestStoredPosition::FExchangeName => $exchangeName,
			BacktestStoredPosition::FTicker => $pair->getTicker(),
			BacktestStoredPosition::FMarketType => $pair->getMarketType()->value,
		];
		$table = BacktestStoredPosition::getTableName();
		$finishedCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::FINISHED->value]));
		$openCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::OPEN->value]));
		$pendingCount = $this->database->countRows($table, array_merge($marketWhere, [BacktestStoredPosition::FStatus => PositionStatusEnum::PENDING->value]));

		$lastClose = $state->lastCandle !== null ? $state->lastCandle->getClosePrice() : 0.0;
		$firstOpen = !empty($candles) ? $candles[0]->getOpenPrice() : 0.0;
		$simEndTime = $state->lastCandle !== null ? ((int) $state->lastCandle->getOpenTime() + $state->candleDuration - 1) : time();
		$simStartTime = !empty($candles) ? (int) $candles[0]->getOpenTime() : $simEndTime;

		$longestLosingDuration = $state->longestLosingDuration;
		$trailingLosingDuration = $simEndTime - $state->peakEquityTime;
		if ($trailingLosingDuration > $longestLosingDuration) {
			$longestLosingDuration = $trailingLosingDuration;
		}

		$exchangeClass = "\\Izzy\\Exchanges\\$exchangeName\\$exchangeName";
		$exchangeTicker = class_exists($exchangeClass) && method_exists($exchangeClass, 'pairToTicker')
			? $exchangeClass::pairToTicker($pair)
			: '';
		$simDurationDays = max(0, $simEndTime - $simStartTime) / 86400;

		$whereOpen = array_merge($marketWhere, [
			BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
		]);
		$openPositions = $this->database->selectAllObjects(BacktestStoredPosition::class, $whereOpen, '');
		$openPositionDtos = [];
		$totalUnrealizedPnl = 0.0;
		foreach ($openPositions as $pos) {
			$vol = $pos->getVolume()->getAmount();
			$entry = $pos->getAverageEntryPrice()->getAmount();
			$unrealizedPnl = $pos->getDirection()->isLong()
				? $vol * ($lastClose - $entry)
				: $vol * ($entry - $lastClose);
			$totalUnrealizedPnl += $unrealizedPnl;
			$openPositionDtos[] = new BacktestOpenPosition(
				direction: $pos->getDirection()->value,
				entry: $entry,
				volume: $vol,
				createdAt: $pos->getCreatedAt(),
				unrealizedPnl: $unrealizedPnl,
				timeHangingSec: $simEndTime - $pos->getCreatedAt(),
			);
		}
		if (!$state->liquidated) {
			$finalBalance += $totalUnrealizedPnl;
		}

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

		foreach ($openPositions as $pos) {
			$created = $pos->getCreatedAt();
			if ($created > 0) {
				$tradeIntervals[] = [$created, $simEndTime];
			}
		}

		return new BacktestResult(
			pair: $backtestPair,
			simStartTime: $simStartTime,
			simEndTime: $simEndTime,
			financial: new BacktestFinancialResult(
				initialBalance: $initialBalance,
				finalBalance: $finalBalance,
				maxDrawdown: $state->maxDrawdown,
				liquidated: $state->liquidated,
				coinPriceStart: $firstOpen,
				coinPriceEnd: $lastClose,
				totalFees: $state->totalFees,
				longestLosingDuration: $longestLosingDuration,
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
	}
}
