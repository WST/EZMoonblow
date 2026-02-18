<?php

namespace Izzy\Backtest;

/**
 * Writes backtest events as JSONL (one JSON object per line) to a file.
 * Used by the web-based visual backtester to stream progress to the browser
 * via SSE. Each write is immediately flushed so the SSE reader can tail
 * the file in near real-time.
 */
class BacktestEventWriter
{
	/** @var resource|false File handle. */
	private $fh;

	/**
	 * @param string $filePath Path to the JSONL output file.
	 */
	public function __construct(string $filePath) {
		$this->fh = fopen($filePath, 'ab');
	}

	public function __destruct() {
		if (is_resource($this->fh)) {
			fclose($this->fh);
		}
	}

	/**
	 * Write a single event line.
	 *
	 * @param string $type Event type identifier.
	 * @param array $data Additional event payload.
	 */
	private function write(string $type, array $data = []): void {
		if (!is_resource($this->fh)) {
			return;
		}
		$data['type'] = $type;
		fwrite($this->fh, json_encode($data, JSON_UNESCAPED_UNICODE) . "\n");
		fflush($this->fh);
	}

	/**
	 * Backtest session metadata.
	 */
	public function writeInit(
		string $pair,
		string $timeframe,
		string $strategy,
		array $params,
		float $initialBalance,
		int $totalCandles,
	): void {
		$this->write('init', [
			'pair' => $pair,
			'timeframe' => $timeframe,
			'strategy' => $strategy,
			'params' => $params,
			'initialBalance' => $initialBalance,
			'totalCandles' => $totalCandles,
		]);
	}

	/**
	 * Completed candle with OHLCV + optional indicator values.
	 *
	 * @param int $time Candle open timestamp.
	 * @param float $open Open price.
	 * @param float $high High price.
	 * @param float $low Low price.
	 * @param float $close Close price.
	 * @param float $volume Volume.
	 * @param array $indicators Associative array of indicator values (e.g. bb_upper, bb_middle, bb_lower, ema).
	 */
	public function writeCandle(int $time, float $open, float $high, float $low, float $close, float $volume, array $indicators = []): void {
		$this->write('candle', [
			't' => $time,
			'o' => $open,
			'h' => $high,
			'l' => $low,
			'c' => $close,
			'v' => $volume,
			'ind' => $indicators,
		]);
	}

	/**
	 * Position opened.
	 */
	public function writePositionOpen(string $direction, float $price, float $volume, int $time): void {
		$this->write('position_open', [
			'dir' => $direction,
			'price' => $price,
			'volume' => $volume,
			'time' => $time,
		]);
	}

	/**
	 * Position closed (TP, SL, or liquidation).
	 */
	public function writePositionClose(float $price, float $pnl, string $reason, int $time): void {
		$this->write('position_close', [
			'price' => $price,
			'pnl' => $pnl,
			'reason' => $reason,
			'time' => $time,
		]);
	}

	/**
	 * Breakeven Lock executed.
	 */
	public function writeBreakevenLock(float $closeVolume, float $slPrice, float $lockedProfit, int $time): void {
		$this->write('breakeven_lock', [
			'closeVolume' => $closeVolume,
			'slPrice' => $slPrice,
			'lockedProfit' => $lockedProfit,
			'time' => $time,
		]);
	}

	/**
	 * Partial Close executed (no SL movement).
	 */
	public function writePartialClose(float $closeVolume, float $closePrice, float $lockedProfit, int $time): void {
		$this->write('partial_close', [
			'closeVolume' => $closeVolume,
			'closePrice' => $closePrice,
			'lockedProfit' => $lockedProfit,
			'time' => $time,
		]);
	}

	/**
	 * DCA averaging fill.
	 */
	public function writeDCAFill(string $direction, float $price, float $addedVolume, float $newAvgEntry, float $totalVolume, int $time): void {
		$this->write('dca_fill', [
			'dir' => $direction,
			'price' => $price,
			'addedVolume' => $addedVolume,
			'newAvgEntry' => $newAvgEntry,
			'totalVolume' => $totalVolume,
			'time' => $time,
		]);
	}

	/**
	 * Balance update after a trade event.
	 */
	public function writeBalance(float $value): void {
		$this->write('balance', ['value' => $value]);
	}

	/**
	 * Progress indicator (candles processed so far).
	 */
	public function writeProgress(int $current, int $total): void {
		$this->write('progress', ['current' => $current, 'total' => $total]);
	}

	/**
	 * Final backtest result summary.
	 */
	public function writeResult(array $summary): void {
		$this->write('result', $summary);
	}

	/**
	 * Error message.
	 */
	public function writeError(string $message): void {
		$this->write('error', ['message' => $message]);
	}

	/**
	 * Backtest finished.
	 */
	public function writeDone(): void {
		$this->write('done');
	}
}
