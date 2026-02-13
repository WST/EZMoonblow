<?php

namespace Izzy\Financial;

use Izzy\System\Database\Database;

/**
 * Repository for loading and saving runtime OHLCV candles requested by indicators/strategies.
 * Uses the runtime_candles table, separate from backtesting data.
 */
class RuntimeCandleRepository extends AbstractCandleRepository
{
	public function __construct(Database $database) {
		parent::__construct($database);
	}

	/**
	 * @inheritDoc
	 */
	protected function getTable(): string {
		return 'runtime_candles';
	}

	/**
	 * @inheritDoc
	 */
	protected function getColumnPrefix(): string {
		return 'runtime_candle_';
	}
}
