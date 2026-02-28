<?php

namespace Izzy\Web\Filters;

use Izzy\Financial\CandleRepository;
use Izzy\Web\Table\TableFilter;

class CandlesFilter
{
	public static function create(CandleRepository $repo): TableFilter {
		$filter = new TableFilter();

		$exchanges = $repo->getDistinctColumnValues('exchange_name');
		$filter->addMultiSelect('exchange', 'Exchange', $exchanges);

		$tickers = $repo->getDistinctColumnValues('ticker');
		$filter->addMultiSelect('ticker', 'Pair', $tickers);

		$marketTypes = $repo->getDistinctColumnValues('market_type');
		$filter->addMultiSelect('marketType', 'Market Type', $marketTypes);

		$timeframes = $repo->getDistinctColumnValues('timeframe');
		$filter->addMultiSelect('timeframe', 'Timeframe', $timeframes);

		return $filter;
	}
}
