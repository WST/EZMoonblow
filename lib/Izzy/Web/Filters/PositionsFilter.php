<?php

namespace Izzy\Web\Filters;

use Izzy\Financial\StoredPosition;
use Izzy\System\Database\Database;
use Izzy\Web\Table\TableFilter;

/**
 * Filter definition for the Positions page.
 * All option lists are built dynamically from existing data.
 */
class PositionsFilter
{
	public static function create(Database $database): TableFilter {
		$filter = new TableFilter();

		$directions = StoredPosition::getDistinctValues($database, StoredPosition::FDirection);
		$filter->addMultiSelect('direction', 'Direction', $directions);

		$marketTypes = StoredPosition::getDistinctValues($database, StoredPosition::FMarketType);
		$filter->addMultiSelect('marketType', 'Market Type', $marketTypes);

		$exchanges = StoredPosition::getDistinctValues($database, StoredPosition::FExchangeName);
		$filter->addMultiSelect('exchange', 'Exchange', $exchanges);

		$tickers = StoredPosition::getDistinctValues($database, StoredPosition::FTicker);
		$filter->addMultiSelect('ticker', 'Pair', $tickers);

		$statuses = StoredPosition::getDistinctValues($database, StoredPosition::FStatus);
		$filter->addMultiSelect('status', 'Status', $statuses);

		$filter->addDateCondition('created', 'Created');
		$filter->addDateCondition('finished', 'Finished');

		return $filter;
	}
}
