<?php

namespace Izzy\Web\Filters;

use Izzy\Backtest\BacktestResultRecord;
use Izzy\System\Database\Database;
use Izzy\Web\Table\TableFilter;

class BacktestResultsFilter
{
	public static function create(Database $database): TableFilter {
		$filter = new TableFilter();

		$exchanges = BacktestResultRecord::getDistinctValues($database, BacktestResultRecord::FExchangeName);
		$tickers = BacktestResultRecord::getDistinctValues($database, BacktestResultRecord::FTicker);
		$timeframes = BacktestResultRecord::getDistinctValues($database, BacktestResultRecord::FTimeframe);
		$strategies = BacktestResultRecord::getDistinctStrategyNames($database);

		$filter->addMultiSelect('exchange', 'Exchange', $exchanges);
		$filter->addMultiSelect('ticker', 'Pair', $tickers);
		$filter->addSelect('marketType', 'Market', ['' => 'All', 'spot' => 'Spot', 'futures' => 'Futures']);
		$filter->addMultiSelect('timeframe', 'Timeframe', $timeframes);
		$filter->addMultiSelect('strategy', 'Strategy', $strategies);
		$filter->addNumberInput('minDuration', 'Min days', 'e.g. 7');
		$filter->addSelect('sortBy', 'Sort by', [
			'' => 'Date (newest)',
			'pnl' => 'PnL',
			'winRate' => 'Win Rate',
			'trades' => 'Trades',
			'sharpe' => 'Sharpe',
		]);
		$filter->addSelect('groupBy', 'Group by', [
			'' => 'None',
			'ticker' => 'Pair',
			'strategy' => 'Strategy',
			'timeframe' => 'TF',
		]);
		$filter->addSelect('groupShow', 'Show', [
			'bestPnl' => 'Best PnL',
			'bestWinRate' => 'Best Win Rate',
			'maxTrades' => 'Max Trades',
			'maxSharpe' => 'Max Sharpe',
		]);

		return $filter;
	}
}
