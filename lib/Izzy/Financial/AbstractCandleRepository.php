<?php

namespace Izzy\Financial;

use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IPair;
use Izzy\System\Database\Database;

/**
 * Base repository for loading and saving OHLCV candles.
 * Subclasses define the target table and column prefix.
 */
abstract class AbstractCandleRepository
{
	protected Database $database;

	public function __construct(Database $database) {
		$this->database = $database;
	}

	/**
	 * Get the database table name for this repository.
	 */
	abstract protected function getTable(): string;

	/**
	 * Get the column prefix used for this table (e.g. "candle_" or "runtime_candle_").
	 */
	abstract protected function getColumnPrefix(): string;

	/**
	 * Save candles to the database (idempotent: INSERT IGNORE by unique key).
	 *
	 * @param string $exchange Exchange name.
	 * @param string $ticker Pair ticker (e.g. "SOL/USDT").
	 * @param string $marketType Market type: 'spot' or 'futures'.
	 * @param string $timeframe Timeframe value (e.g. "4h").
	 * @param array $candles Array of ICandle instances or arrays with open_time, open, high, low, close, volume.
	 * @return int Number of candles saved (inserted).
	 */
	public function saveCandles(string $exchange, string $ticker, string $marketType, string $timeframe, array $candles): int {
		if (empty($candles)) {
			return 0;
		}
		$saved = 0;
		foreach ($candles as $candle) {
			$row = $this->candleToRow($candle, $exchange, $ticker, $marketType, $timeframe);
			if (empty($row)) {
				continue;
			}
			if ($this->database->insertIgnore($this->getTable(), $row)) {
				$saved++;
			}
		}
		return $saved;
	}

	/**
	 * Load candles from the database for the given pair and time range.
	 *
	 * @param IPair $pair Trading pair (exchange name and market type taken from pair).
	 * @param int $startTime Unix timestamp (seconds), inclusive.
	 * @param int $endTime Unix timestamp (seconds), inclusive.
	 * @return Candle[] Array of Candle instances, ordered by open time ascending.
	 */
	public function getCandles(IPair $pair, int $startTime, int $endTime): array {
		$p = $this->getColumnPrefix();
		$exchange = $this->database->quote($pair->getExchangeName());
		$ticker = $this->database->quote($pair->getTicker());
		$marketType = $this->database->quote($pair->getMarketType()->value);
		$timeframe = $this->database->quote($pair->getTimeframe()->value);
		$start = (int)$startTime;
		$end = (int)$endTime;
		$where = "{$p}exchange_name = $exchange AND {$p}ticker = $ticker AND {$p}market_type = $marketType AND {$p}timeframe = $timeframe AND {$p}open_time >= $start AND {$p}open_time <= $end";
		$rows = $this->database->selectAllRows($this->getTable(), '*', $where, "{$p}open_time ASC");
		return array_map(fn(array $row) => $this->rowToCandle($row), $rows);
	}

	/**
	 * Check whether the repository has candles covering the given range.
	 *
	 * @param IPair $pair Trading pair.
	 * @param string $timeframe Timeframe value.
	 * @param int $startTime Unix timestamp (seconds), inclusive.
	 * @param int $endTime Unix timestamp (seconds), inclusive.
	 * @return bool True if at least one candle exists in the range.
	 */
	public function hasCandles(IPair $pair, string $timeframe, int $startTime, int $endTime): bool {
		$p = $this->getColumnPrefix();
		$exchange = $this->database->quote($pair->getExchangeName());
		$ticker = $this->database->quote($pair->getTicker());
		$marketType = $this->database->quote($pair->getMarketType()->value);
		$tf = $this->database->quote($timeframe);
		$start = (int)$startTime;
		$end = (int)$endTime;
		$where = "{$p}exchange_name = $exchange AND {$p}ticker = $ticker AND {$p}market_type = $marketType AND {$p}timeframe = $tf AND {$p}open_time >= $start AND {$p}open_time <= $end";
		return $this->database->countRows($this->getTable(), $where) > 0;
	}

	/**
	 * Get the open_time of the most recent candle in the given range.
	 *
	 * @param IPair $pair Trading pair.
	 * @param string $timeframe Timeframe value.
	 * @param int $startTime Unix timestamp (seconds), inclusive.
	 * @param int $endTime Unix timestamp (seconds), inclusive.
	 * @return int|null Latest open_time, or null if no candles exist.
	 */
	public function getLatestCandleTime(IPair $pair, string $timeframe, int $startTime, int $endTime): ?int {
		$p = $this->getColumnPrefix();
		$exchange = $this->database->quote($pair->getExchangeName());
		$ticker = $this->database->quote($pair->getTicker());
		$marketType = $this->database->quote($pair->getMarketType()->value);
		$tf = $this->database->quote($timeframe);
		$start = (int)$startTime;
		$end = (int)$endTime;
		$where = "{$p}exchange_name = $exchange AND {$p}ticker = $ticker AND {$p}market_type = $marketType AND {$p}timeframe = $tf AND {$p}open_time >= $start AND {$p}open_time <= $end";
		$row = $this->database->selectOneRow($this->getTable(), "MAX({$p}open_time) AS latest", $where);
		if ($row === false || $row['latest'] === null) {
			return null;
		}
		return (int)$row['latest'];
	}

	/**
	 * Get the open_time of the earliest candle in the given range.
	 *
	 * @param IPair $pair Trading pair.
	 * @param string $timeframe Timeframe value.
	 * @param int $startTime Unix timestamp (seconds), inclusive.
	 * @param int $endTime Unix timestamp (seconds), inclusive.
	 * @return int|null Earliest open_time, or null if no candles exist.
	 */
	public function getEarliestCandleTime(IPair $pair, string $timeframe, int $startTime, int $endTime): ?int {
		$p = $this->getColumnPrefix();
		$exchange = $this->database->quote($pair->getExchangeName());
		$ticker = $this->database->quote($pair->getTicker());
		$marketType = $this->database->quote($pair->getMarketType()->value);
		$tf = $this->database->quote($timeframe);
		$start = (int)$startTime;
		$end = (int)$endTime;
		$where = "{$p}exchange_name = $exchange AND {$p}ticker = $ticker AND {$p}market_type = $marketType AND {$p}timeframe = $tf AND {$p}open_time >= $start AND {$p}open_time <= $end";
		$row = $this->database->selectOneRow($this->getTable(), "MIN({$p}open_time) AS earliest", $where);
		if ($row === false || $row['earliest'] === null) {
			return null;
		}
		return (int)$row['earliest'];
	}

	/**
	 * Get distinct (exchange, ticker, market_type, timeframe) combos that have candle data.
	 *
	 * @return array<int, array{exchange: string, ticker: string, marketType: string, timeframe: string}>
	 */
	public function getAvailablePairs(): array {
		$p = $this->getColumnPrefix();
		$table = $this->getTable();
		$sql = "SELECT DISTINCT {$p}exchange_name AS exchange_name, {$p}ticker AS ticker, "
			. "{$p}market_type AS market_type, {$p}timeframe AS timeframe FROM {$table}";
		$rows = $this->database->queryAllRows($sql);
		$result = [];
		foreach ($rows as $row) {
			$result[] = [
				'exchange' => $row['exchange_name'],
				'ticker' => $row['ticker'],
				'marketType' => $row['market_type'],
				'timeframe' => $row['timeframe'],
			];
		}
		return $result;
	}

	/**
	 * Delete candles older than the given timestamp.
	 *
	 * @param int $olderThan Unix timestamp (seconds). Candles with open_time < this value are deleted.
	 * @return bool True on success.
	 */
	public function deleteOlderThan(int $olderThan): bool {
		$p = $this->getColumnPrefix();
		$sql = "{$p}open_time < $olderThan";
		return $this->database->delete($this->getTable(), $sql);
	}

	/**
	 * Convert a single candle (ICandle or array) to a database row.
	 *
	 * @param ICandle|array $candle Candle instance or array with open_time, open, high, low, close, volume.
	 * @param string $exchange Exchange name.
	 * @param string $ticker Ticker.
	 * @param string $marketType Market type.
	 * @param string $timeframe Timeframe.
	 * @return array<string, mixed> Row for insert.
	 */
	private function candleToRow(mixed $candle, string $exchange, string $ticker, string $marketType, string $timeframe): array {
		if ($candle instanceof ICandle) {
			$openTime = $candle->getOpenTime();
			$open = $candle->getOpenPrice();
			$high = $candle->getHighPrice();
			$low = $candle->getLowPrice();
			$close = $candle->getClosePrice();
			$volume = $candle->getVolume();
		} elseif (is_array($candle) && isset($candle['open_time'], $candle['open'], $candle['high'], $candle['low'], $candle['close'], $candle['volume'])) {
			$openTime = (int)$candle['open_time'];
			$open = (float)$candle['open'];
			$high = (float)$candle['high'];
			$low = (float)$candle['low'];
			$close = (float)$candle['close'];
			$volume = (float)$candle['volume'];
		} else {
			return [];
		}
		if ($openTime <= 0) {
			return [];
		}
		$p = $this->getColumnPrefix();
		return [
			"{$p}exchange_name" => $exchange,
			"{$p}ticker" => $ticker,
			"{$p}market_type" => $marketType,
			"{$p}timeframe" => $timeframe,
			"{$p}open_time" => $openTime,
			"{$p}open" => $open,
			"{$p}high" => $high,
			"{$p}low" => $low,
			"{$p}close" => $close,
			"{$p}volume" => $volume,
		];
	}

	/**
	 * Convert a database row to a Candle instance.
	 *
	 * @param array $row Database row.
	 * @return Candle Candle instance.
	 */
	private function rowToCandle(array $row): Candle {
		$p = $this->getColumnPrefix();
		return new Candle(
			timestamp: (int)$row["{$p}open_time"],
			open: (float)$row["{$p}open"],
			high: (float)$row["{$p}high"],
			low: (float)$row["{$p}low"],
			close: (float)$row["{$p}close"],
			volume: (float)$row["{$p}volume"]
		);
	}
}
