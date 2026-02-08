<?php

namespace Izzy\Financial;

use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IPair;
use Izzy\System\Database\Database;

/**
 * Repository for loading and saving OHLCV candles to the database (backtesting storage).
 */
class CandleRepository
{
	private const string TABLE = 'candles';

	private Database $database;

	public function __construct(Database $database) {
		$this->database = $database;
	}

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
			if ($this->database->insertIgnore(self::TABLE, $row)) {
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
	 * @return Candle[] Array of Candle instances (without market set), ordered by open time ascending.
	 */
	public function getCandles(IPair $pair, int $startTime, int $endTime): array {
		$exchange = $this->database->quote($pair->getExchangeName());
		$ticker = $this->database->quote($pair->getTicker());
		$marketType = $this->database->quote($pair->getMarketType()->value);
		$timeframe = $this->database->quote($pair->getTimeframe()->value);
		$start = (int)$startTime;
		$end = (int)$endTime;
		$where = "candle_exchange_name = $exchange AND candle_ticker = $ticker AND candle_market_type = $marketType AND candle_timeframe = $timeframe AND candle_open_time >= $start AND candle_open_time <= $end";
		$rows = $this->database->selectAllRows(self::TABLE, '*', $where, 'candle_open_time ASC');
		return array_map(fn(array $row) => $this->rowToCandle($row), $rows);
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
		return [
			'candle_exchange_name' => $exchange,
			'candle_ticker' => $ticker,
			'candle_market_type' => $marketType,
			'candle_timeframe' => $timeframe,
			'candle_open_time' => $openTime,
			'candle_open' => $open,
			'candle_high' => $high,
			'candle_low' => $low,
			'candle_close' => $close,
			'candle_volume' => $volume,
		];
	}

	private function rowToCandle(array $row): Candle {
		return new Candle(
			timestamp: (int)$row['candle_open_time'],
			open: (float)$row['candle_open'],
			high: (float)$row['candle_high'],
			low: (float)$row['candle_low'],
			close: (float)$row['candle_close'],
			volume: (float)$row['candle_volume']
		);
	}
}
