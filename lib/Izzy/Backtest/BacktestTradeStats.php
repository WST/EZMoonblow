<?php

namespace Izzy\Backtest;

use Izzy\Traits\ConsoleTableTrait;
use Stringable;

/**
 * DTO for the "Trades" section of the backtest summary.
 */
readonly class BacktestTradeStats implements Stringable
{
	use ConsoleTableTrait;

	public function __construct(
		public int $finished,
		public int $open,
		public int $pending,
		public int $shortest,
		public int $longest,
		public int $average,
		public int $idle,
		public int $wins,
		public int $losses,
	) {
	}

	/**
	 * Build a BacktestTradeStats from raw finished-position data.
	 *
	 * @param int[] $durations Trade durations in seconds.
	 * @param array<array{0: int, 1: int}> $intervals [created_at, finished_at] pairs for all positions.
	 * @param int $simStart Simulation start timestamp.
	 * @param int $simEnd Simulation end timestamp.
	 * @param int $finished Number of finished trades.
	 * @param int $open Number of open positions.
	 * @param int $pending Number of pending positions.
	 * @param int $wins Number of winning trades.
	 * @param int $losses Number of losing trades.
	 * @return self
	 */
	public static function fromRawData(
		array $durations,
		array $intervals,
		int $simStart,
		int $simEnd,
		int $finished,
		int $open,
		int $pending,
		int $wins,
		int $losses,
	): self {
		$stats = self::computeDurationStats($durations, $intervals, $simStart, $simEnd);
		return new self(
			finished: $finished,
			open: $open,
			pending: $pending,
			shortest: $stats['shortest'],
			longest: $stats['longest'],
			average: $stats['average'],
			idle: $stats['idle'],
			wins: $wins,
			losses: $losses,
		);
	}

	/**
	 * Compute trade duration statistics and idle time.
	 *
	 * @param int[] $durations Array of trade durations in seconds.
	 * @param array<array{0: int, 1: int}> $intervals [created_at, finished_at] pairs.
	 * @param int $simStart Simulation start timestamp.
	 * @param int $simEnd Simulation end timestamp.
	 * @return array{shortest: int, longest: int, average: int, idle: int}
	 */
	private static function computeDurationStats(array $durations, array $intervals, int $simStart, int $simEnd): array
	{
		$shortest = 0;
		$longest = 0;
		$average = 0;
		if (count($durations) > 0) {
			$shortest = min($durations);
			$longest = max($durations);
			$average = (int) round(array_sum($durations) / count($durations));
		}

		// Compute time without any open positions by merging overlapping intervals.
		$totalSpan = max(0, $simEnd - $simStart);
		$coveredTime = 0;
		if (count($intervals) > 0) {
			usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);
			$merged = [$intervals[0]];
			for ($i = 1; $i < count($intervals); $i++) {
				$last = &$merged[count($merged) - 1];
				if ($intervals[$i][0] <= $last[1]) {
					$last[1] = max($last[1], $intervals[$i][1]);
				} else {
					$merged[] = $intervals[$i];
				}
			}
			unset($last);
			foreach ($merged as $m) {
				$start = max($m[0], $simStart);
				$end = min($m[1], $simEnd);
				if ($end > $start) {
					$coveredTime += $end - $start;
				}
			}
		}

		return [
			'shortest' => $shortest,
			'longest' => $longest,
			'average' => $average,
			'idle' => max(0, $totalSpan - $coveredTime),
		];
	}

	public function __toString(): string
	{
		$h = ['Metric', 'Value'];
		$rows = [
			['Finished', (string) $this->finished],
			['Open', (string) $this->open],
			['Pending', (string) $this->pending],
		];
		if ($this->finished > 0) {
			$rows[] = ['Shortest trade', $this->formatDuration($this->shortest)];
			$rows[] = ['Longest trade', $this->formatDuration($this->longest)];
			$rows[] = ['Average duration', $this->formatDuration($this->average)];
			$rows[] = ['Time without positions', $this->formatDuration($this->idle)];
			$total = $this->wins + $this->losses;
			$winRate = $total > 0 ? ($this->wins / $total) * 100 : 0;
			$rows[] = ['Win / Loss', "{$this->wins} / {$this->losses} (" . number_format($winRate, 1) . '% win rate)'];
		}
		return $this->renderTable('Trades', $h, $rows);
	}
}
