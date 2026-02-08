<?php

namespace Izzy\Backtest;

use Izzy\Financial\Pair;
use Izzy\Traits\ConsoleTableTrait;
use Stringable;

/**
 * Complete backtest result for a single pair.
 * Encapsulates all sections of the summary and renders them via __toString().
 */
readonly class BacktestResult implements Stringable
{
	use ConsoleTableTrait;

	/**
	 * @param Pair $pair The trading pair that was backtested.
	 * @param int $simStartTime Simulation start timestamp.
	 * @param int $simEndTime Simulation end timestamp.
	 * @param BacktestFinancialResult $financial Financial metrics.
	 * @param BacktestTradeStats $trades Trade statistics.
	 * @param BacktestRiskRatios|null $risk Risk ratios (null if insufficient data).
	 * @param BacktestOpenPosition[] $openPositions Open/pending positions at end.
	 */
	public function __construct(
		public Pair $pair,
		public int $simStartTime,
		public int $simEndTime,
		public BacktestFinancialResult $financial,
		public BacktestTradeStats $trades,
		public ?BacktestRiskRatios $risk,
		public array $openPositions,
	) {
	}

	public function __toString(): string
	{
		$out = '';

		// --- Period ---
		$ticker = $this->pair->getTicker();
		$marketType = $this->pair->getMarketType()->value;
		$timeframe = $this->pair->getTimeframe()->value;
		$exchangeName = $this->pair->getExchangeName();
		$strategyName = $this->pair->getStrategyName();
		$statusStr = $this->financial->liquidated ? 'LIQUIDATED (simulation stopped)' : 'Completed';

		$simDurationDays = max(0, $this->simEndTime - $this->simStartTime) / 86400;
		$h = ['Metric', 'Value'];
		$out .= $this->renderTable('Period', $h, [
			['Pair', "$ticker $marketType $timeframe ($exchangeName)"],
			['Strategy', $strategyName],
			['Status', $statusStr],
			['Period start', $this->simStartTime > 0 ? date('Y-m-d H:i', $this->simStartTime) : 'N/A'],
			['Period end', $this->simEndTime > 0 ? date('Y-m-d H:i', $this->simEndTime) : 'N/A'],
			['Duration', number_format($simDurationDays, 1) . ' days'],
		]);

		// --- Financial ---
		$out .= (string) $this->financial;

		// --- Trades ---
		$out .= (string) $this->trades;

		// --- Risk Ratios ---
		if ($this->risk !== null) {
			$out .= (string) $this->risk;
		}

		// --- Open / Pending positions ---
		if ($this->openPositions !== []) {
			$posHeaders = ['Direction', 'Entry', 'Volume', 'Created', 'Time open', 'Unrealized PnL'];
			$posRows = [];
			foreach ($this->openPositions as $p) {
				$posRows[] = [
					$p->direction,
					number_format($p->entry, 4),
					number_format($p->volume, 4),
					date('Y-m-d H:i', $p->createdAt),
					$this->formatDuration($p->timeHangingSec),
					number_format($p->unrealizedPnl, 2) . ' USDT',
				];
			}
			$out .= $this->renderTable('Open / Pending positions at end', $posHeaders, $posRows);
		}

		$out .= PHP_EOL;
		return $out;
	}
}
