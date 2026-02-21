<?php

namespace Izzy\Financial;

use Izzy\System\Database\Database;

/**
 * Repository for storing and loading live market candles fetched by Trader from the exchange API.
 * Used by the web interface and other components to access candle data without API calls.
 */
class MarketCandleRepository extends AbstractCandleRepository
{
	public function __construct(Database $database) {
		parent::__construct($database);
	}

	protected function getTable(): string {
		return 'market_candles';
	}

	protected function getColumnPrefix(): string {
		return 'mc_';
	}
}
