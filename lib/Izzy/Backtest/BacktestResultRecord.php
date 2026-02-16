<?php

namespace Izzy\Backtest;

use Izzy\Financial\StrategyFactory;
use Izzy\System\Database\Database;
use Izzy\System\Database\ORM\SurrogatePKDatabaseRecord;

/**
 * Persisted backtest result record.
 * Stores all summary metrics so they can be displayed on the Results page.
 */
class BacktestResultRecord extends SurrogatePKDatabaseRecord
{
	const string FId = 'br_id';
	const string FExchangeName = 'br_exchange_name';
	const string FTicker = 'br_ticker';
	const string FMarketType = 'br_market_type';
	const string FTimeframe = 'br_timeframe';
	const string FStrategy = 'br_strategy';
	const string FStrategyParams = 'br_strategy_params';
	const string FInitialBalance = 'br_initial_balance';
	const string FFinalBalance = 'br_final_balance';
	const string FPnl = 'br_pnl';
	const string FPnlPercent = 'br_pnl_percent';
	const string FMaxDrawdown = 'br_max_drawdown';
	const string FLiquidated = 'br_liquidated';
	const string FCoinPriceStart = 'br_coin_price_start';
	const string FCoinPriceEnd = 'br_coin_price_end';
	const string FTradesFinished = 'br_trades_finished';
	const string FTradesOpen = 'br_trades_open';
	const string FTradesPending = 'br_trades_pending';
	const string FTradesWins = 'br_trades_wins';
	const string FTradesLosses = 'br_trades_losses';
	const string FTradesBL = 'br_trades_bl';
	const string FTradeShortest = 'br_trade_shortest';
	const string FTradeLongest = 'br_trade_longest';
	const string FTradeAverage = 'br_trade_average';
	const string FTradeIdle = 'br_trade_idle';
	const string FSharpe = 'br_sharpe';
	const string FSortino = 'br_sortino';
	const string FAvgReturn = 'br_avg_return';
	const string FStdDeviation = 'br_std_deviation';
	const string FLongFinished = 'br_long_finished';
	const string FLongWins = 'br_long_wins';
	const string FLongLosses = 'br_long_losses';
	const string FLongBL = 'br_long_bl';
	const string FLongShortest = 'br_long_shortest';
	const string FLongLongest = 'br_long_longest';
	const string FLongAverage = 'br_long_average';
	const string FShortFinished = 'br_short_finished';
	const string FShortWins = 'br_short_wins';
	const string FShortLosses = 'br_short_losses';
	const string FShortBL = 'br_short_bl';
	const string FShortShortest = 'br_short_shortest';
	const string FShortLongest = 'br_short_longest';
	const string FShortAverage = 'br_short_average';
	const string FSimStart = 'br_sim_start';
	const string FSimEnd = 'br_sim_end';
	const string FCreatedAt = 'br_created_at';
	const string FOpenPositions = 'br_open_positions';

	public function __construct(Database $database, array $row) {
		parent::__construct($database, $row, self::FId);
	}

	public static function getTableName(): string {
		return 'backtest_results';
	}

	/**
	 * Persist a BacktestResult DTO into the database.
	 */
	public static function saveFromResult(Database $database, BacktestResult $result): void {
		$pair = $result->pair;
		$fin = $result->financial;
		$trades = $result->trades;
		$risk = $result->risk;
		$long = $result->longStats;
		$short = $result->shortStats;

		$openPositionsJson = null;
		if (!empty($result->openPositions)) {
			$openPositionsJson = json_encode(array_map(fn(BacktestOpenPosition $p) => [
				'direction' => $p->direction,
				'entry' => $p->entry,
				'volume' => $p->volume,
				'createdAt' => $p->createdAt,
				'unrealizedPnl' => $p->unrealizedPnl,
				'timeHangingSec' => $p->timeHangingSec,
			], $result->openPositions), JSON_UNESCAPED_UNICODE);
		}

		$row = [
			self::FExchangeName => $pair->getExchangeName(),
			self::FTicker => $pair->getTicker(),
			self::FMarketType => $pair->getMarketType()->value,
			self::FTimeframe => $pair->getTimeframe()->value,
			self::FStrategy => $pair->getStrategyName() ?? '',
			self::FStrategyParams => json_encode($pair->getStrategyParams(), JSON_UNESCAPED_UNICODE),
			self::FInitialBalance => $fin->initialBalance,
			self::FFinalBalance => $fin->finalBalance,
			self::FPnl => $fin->getPnl(),
			self::FPnlPercent => $fin->getPnlPercent(),
			self::FMaxDrawdown => $fin->maxDrawdown,
			self::FLiquidated => $fin->liquidated ? 1 : 0,
			self::FCoinPriceStart => $fin->coinPriceStart,
			self::FCoinPriceEnd => $fin->coinPriceEnd,
			self::FTradesFinished => $trades->finished,
			self::FTradesOpen => $trades->open,
			self::FTradesPending => $trades->pending,
			self::FTradesWins => $trades->wins,
			self::FTradesLosses => $trades->losses,
			self::FTradesBL => $trades->breakevenLocks,
			self::FTradeShortest => $trades->shortest,
			self::FTradeLongest => $trades->longest,
			self::FTradeAverage => $trades->average,
			self::FTradeIdle => $trades->idle,
			self::FSharpe => $risk?->sharpe,
			self::FSortino => $risk?->sortino,
			self::FAvgReturn => $risk?->avgReturn,
			self::FStdDeviation => $risk?->stdDeviation,
			self::FLongFinished => $long?->finished ?? 0,
			self::FLongWins => $long?->wins ?? 0,
			self::FLongLosses => $long?->losses ?? 0,
			self::FLongBL => $long?->breakevenLocks ?? 0,
			self::FLongShortest => $long?->shortest ?? 0,
			self::FLongLongest => $long?->longest ?? 0,
			self::FLongAverage => $long?->average ?? 0,
			self::FShortFinished => $short?->finished ?? 0,
			self::FShortWins => $short?->wins ?? 0,
			self::FShortLosses => $short?->losses ?? 0,
			self::FShortBL => $short?->breakevenLocks ?? 0,
			self::FShortShortest => $short?->shortest ?? 0,
			self::FShortLongest => $short?->longest ?? 0,
			self::FShortAverage => $short?->average ?? 0,
			self::FSimStart => $result->simStartTime,
			self::FSimEnd => $result->simEndTime,
			self::FCreatedAt => time(),
			self::FOpenPositions => $openPositionsJson,
		];

		$record = new self($database, $row);
		$record->save();
	}

	/**
	 * Load all backtest results, newest first.
	 *
	 * @return self[]
	 */
	public static function loadAll(Database $database): array {
		return $database->selectAllObjects(self::class, [], self::FCreatedAt . ' DESC');
	}

	// ---- Getters ----

	public function getExchangeName(): string {
		return $this->row[self::FExchangeName];
	}

	public function getTicker(): string {
		return $this->row[self::FTicker];
	}

	public function getMarketType(): string {
		return $this->row[self::FMarketType];
	}

	public function getTimeframe(): string {
		return $this->row[self::FTimeframe];
	}

	public function getStrategy(): string {
		return $this->row[self::FStrategy];
	}

	public function getStrategyDisplayName(): string {
		$name = $this->getStrategy();
		$class = StrategyFactory::getStrategyClass($name);
		if ($class !== null && method_exists($class, 'getDisplayName')) {
			return $class::getDisplayName();
		}
		return $name;
	}

	public function getStrategyParams(): array {
		$json = $this->row[self::FStrategyParams] ?? null;
		if ($json === null) {
			return [];
		}
		return json_decode($json, true) ?: [];
	}

	/**
	 * Get strategy params as an array of {label, key, value} for display.
	 * Resolves human-readable labels via StrategyFactory.
	 *
	 * @return array<int, array{label: string, key: string, value: string}>
	 */
	public function getStrategyParamsLabeled(): array {
		$params = $this->getStrategyParams();
		if (empty($params)) {
			return [];
		}

		$labelMap = [];
		$strategyClass = StrategyFactory::getStrategyClass($this->getStrategy());
		if ($strategyClass !== null && method_exists($strategyClass, 'getParameters')) {
			foreach ($strategyClass::getParameters() as $param) {
				$labelMap[$param->getName()] = $param->getLabel();
			}
		}

		$result = [];
		foreach ($params as $key => $value) {
			$result[] = [
				'label' => $labelMap[$key] ?? $key,
				'key' => $key,
				'value' => $value,
			];
		}
		return $result;
	}

	public function getInitialBalance(): float {
		return (float) $this->row[self::FInitialBalance];
	}

	public function getFinalBalance(): float {
		return (float) $this->row[self::FFinalBalance];
	}

	public function getPnl(): float {
		return (float) $this->row[self::FPnl];
	}

	public function getPnlPercent(): float {
		return (float) $this->row[self::FPnlPercent];
	}

	public function getMaxDrawdown(): float {
		return (float) $this->row[self::FMaxDrawdown];
	}

	public function isLiquidated(): bool {
		return (bool) $this->row[self::FLiquidated];
	}

	public function getCoinPriceStart(): float {
		return (float) $this->row[self::FCoinPriceStart];
	}

	public function getCoinPriceEnd(): float {
		return (float) $this->row[self::FCoinPriceEnd];
	}

	public function getTradesFinished(): int {
		return (int) $this->row[self::FTradesFinished];
	}

	public function getTradesOpen(): int {
		return (int) $this->row[self::FTradesOpen];
	}

	public function getTradesPending(): int {
		return (int) $this->row[self::FTradesPending];
	}

	public function getTradesWins(): int {
		return (int) $this->row[self::FTradesWins];
	}

	public function getTradesLosses(): int {
		return (int) $this->row[self::FTradesLosses];
	}

	public function getTradesBL(): int {
		return (int) $this->row[self::FTradesBL];
	}

	public function getTradeShortest(): int {
		return (int) $this->row[self::FTradeShortest];
	}

	public function getTradeLongest(): int {
		return (int) $this->row[self::FTradeLongest];
	}

	public function getTradeAverage(): int {
		return (int) $this->row[self::FTradeAverage];
	}

	public function getTradeIdle(): int {
		return (int) $this->row[self::FTradeIdle];
	}

	public function getSharpe(): ?float {
		return isset($this->row[self::FSharpe]) ? (float) $this->row[self::FSharpe] : null;
	}

	public function getSortino(): ?float {
		return isset($this->row[self::FSortino]) ? (float) $this->row[self::FSortino] : null;
	}

	public function getAvgReturn(): ?float {
		return isset($this->row[self::FAvgReturn]) ? (float) $this->row[self::FAvgReturn] : null;
	}

	public function getStdDeviation(): ?float {
		return isset($this->row[self::FStdDeviation]) ? (float) $this->row[self::FStdDeviation] : null;
	}

	public function getLongFinished(): int {
		return (int) $this->row[self::FLongFinished];
	}

	public function getLongWins(): int {
		return (int) $this->row[self::FLongWins];
	}

	public function getLongLosses(): int {
		return (int) $this->row[self::FLongLosses];
	}

	public function getLongBL(): int {
		return (int) $this->row[self::FLongBL];
	}

	public function getLongShortest(): int {
		return (int) $this->row[self::FLongShortest];
	}

	public function getLongLongest(): int {
		return (int) $this->row[self::FLongLongest];
	}

	public function getLongAverage(): int {
		return (int) $this->row[self::FLongAverage];
	}

	public function getShortFinished(): int {
		return (int) $this->row[self::FShortFinished];
	}

	public function getShortWins(): int {
		return (int) $this->row[self::FShortWins];
	}

	public function getShortLosses(): int {
		return (int) $this->row[self::FShortLosses];
	}

	public function getShortBL(): int {
		return (int) $this->row[self::FShortBL];
	}

	public function getShortShortest(): int {
		return (int) $this->row[self::FShortShortest];
	}

	public function getShortLongest(): int {
		return (int) $this->row[self::FShortLongest];
	}

	public function getShortAverage(): int {
		return (int) $this->row[self::FShortAverage];
	}

	public function getSimStart(): int {
		return (int) $this->row[self::FSimStart];
	}

	public function getSimEnd(): int {
		return (int) $this->row[self::FSimEnd];
	}

	public function getCreatedAt(): int {
		return (int) $this->row[self::FCreatedAt];
	}

	public function getOpenPositions(): array {
		$json = $this->row[self::FOpenPositions] ?? null;
		if ($json === null) {
			return [];
		}
		return json_decode($json, true) ?: [];
	}

	public function getWinRate(): float {
		$total = $this->getTradesWins() + $this->getTradesLosses();
		return $total > 0 ? ($this->getTradesWins() / $total) * 100 : 0.0;
	}

	public function getSimDurationDays(): float {
		return max(0, $this->getSimEnd() - $this->getSimStart()) / 86400;
	}

	/**
	 * Serialize to array for JSON API / Twig template consumption.
	 */
	public function toArray(): array {
		return [
			'id' => $this->getId(),
			'exchangeName' => $this->getExchangeName(),
			'ticker' => $this->getTicker(),
			'marketType' => $this->getMarketType(),
			'timeframe' => $this->getTimeframe(),
			'strategy' => $this->getStrategyDisplayName(),
			'strategyParams' => $this->getStrategyParamsLabeled(),
			'initialBalance' => $this->getInitialBalance(),
			'finalBalance' => $this->getFinalBalance(),
			'pnl' => $this->getPnl(),
			'pnlPercent' => $this->getPnlPercent(),
			'maxDrawdown' => $this->getMaxDrawdown(),
			'liquidated' => $this->isLiquidated(),
			'coinPriceStart' => $this->getCoinPriceStart(),
			'coinPriceEnd' => $this->getCoinPriceEnd(),
			'tradesFinished' => $this->getTradesFinished(),
			'tradesOpen' => $this->getTradesOpen(),
			'tradesPending' => $this->getTradesPending(),
			'tradesWins' => $this->getTradesWins(),
			'tradesLosses' => $this->getTradesLosses(),
			'tradesBL' => $this->getTradesBL(),
			'tradeShortest' => $this->getTradeShortest(),
			'tradeLongest' => $this->getTradeLongest(),
			'tradeAverage' => $this->getTradeAverage(),
			'tradeIdle' => $this->getTradeIdle(),
			'sharpe' => $this->getSharpe(),
			'sortino' => $this->getSortino(),
			'avgReturn' => $this->getAvgReturn(),
			'stdDeviation' => $this->getStdDeviation(),
			'longFinished' => $this->getLongFinished(),
			'longWins' => $this->getLongWins(),
			'longLosses' => $this->getLongLosses(),
			'longBL' => $this->getLongBL(),
			'longShortest' => $this->getLongShortest(),
			'longLongest' => $this->getLongLongest(),
			'longAverage' => $this->getLongAverage(),
			'shortFinished' => $this->getShortFinished(),
			'shortWins' => $this->getShortWins(),
			'shortLosses' => $this->getShortLosses(),
			'shortBL' => $this->getShortBL(),
			'shortShortest' => $this->getShortShortest(),
			'shortLongest' => $this->getShortLongest(),
			'shortAverage' => $this->getShortAverage(),
			'simStart' => $this->getSimStart(),
			'simEnd' => $this->getSimEnd(),
			'createdAt' => $this->getCreatedAt(),
			'winRate' => $this->getWinRate(),
			'simDurationDays' => $this->getSimDurationDays(),
			'openPositions' => $this->getOpenPositions(),
		];
	}
}
