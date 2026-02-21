<?php

namespace Izzy\Financial;

use Izzy\System\Database\Database;

/**
 * Repository for loading and saving OHLCV candles to the database (backtesting storage).
 */
class CandleRepository extends AbstractCandleRepository
{
	public function __construct(Database $database) {
		parent::__construct($database);
	}

	/**
	 * @inheritDoc
	 */
	protected function getTable(): string {
		return 'backtest_candles';
	}

	/**
	 * @inheritDoc
	 */
	protected function getColumnPrefix(): string {
		return 'backtest_candle_';
	}
}
