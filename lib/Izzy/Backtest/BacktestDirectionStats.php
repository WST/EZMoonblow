<?php

namespace Izzy\Backtest;

use Izzy\Traits\ConsoleTableTrait;
use Stringable;

/**
 * Per-direction (Long / Short) trade statistics for the backtest summary.
 */
readonly class BacktestDirectionStats implements Stringable
{
	use ConsoleTableTrait;

	public function __construct(
		public string $label,
		public int $finished,
		public int $wins,
		public int $losses,
		public int $breakevenLocks,
		public int $shortest,
		public int $longest,
		public int $average,
	) {
	}

	/**
	 * Build from raw per-direction data.
	 *
	 * @param string $label Direction label ("Longs" or "Shorts").
	 * @param int[] $durations Trade durations in seconds.
	 * @param int $wins Number of winning trades (TP hit).
	 * @param int $losses Number of losing trades (SL hit without prior BL).
	 * @param int $breakevenLocks Number of trades closed via SL after Breakeven Lock.
	 * @return self
	 */
	public static function fromRawData(
		string $label,
		array $durations,
		int $wins,
		int $losses,
		int $breakevenLocks,
	): self {
		$shortest = 0;
		$longest = 0;
		$average = 0;
		if (count($durations) > 0) {
			$shortest = min($durations);
			$longest = max($durations);
			$average = (int) round(array_sum($durations) / count($durations));
		}
		return new self(
			label: $label,
			finished: $wins + $losses + $breakevenLocks,
			wins: $wins,
			losses: $losses,
			breakevenLocks: $breakevenLocks,
			shortest: $shortest,
			longest: $longest,
			average: $average,
		);
	}

	public function getWinRate(): float {
		$total = $this->wins + $this->losses;
		return $total > 0 ? ($this->wins / $total) * 100 : 0;
	}

	public function __toString(): string {
		$h = ['Metric', 'Value'];
		$rows = [
			['Finished', (string) $this->finished],
		];
		if ($this->finished > 0) {
			$total = $this->finished;
			$winPct = number_format(($this->wins / $total) * 100, 1);
			$lossPct = number_format(($this->losses / $total) * 100, 1);
			$blPct = number_format(($this->breakevenLocks / $total) * 100, 1);

			$rows[] = ['Win (TP)', "{$this->wins} ({$winPct}%)"];
			$rows[] = ['Loss (SL)', "{$this->losses} ({$lossPct}%)"];
			$rows[] = ['Breakeven Lock (SL)', "{$this->breakevenLocks} ({$blPct}%)"];
			$rows[] = ['Win Rate', number_format($this->getWinRate(), 1) . '%'];
			$rows[] = ['Shortest trade', $this->formatDuration($this->shortest)];
			$rows[] = ['Longest trade', $this->formatDuration($this->longest)];
			$rows[] = ['Average duration', $this->formatDuration($this->average)];
		}
		return $this->renderTable($this->label, $h, $rows);
	}
}
