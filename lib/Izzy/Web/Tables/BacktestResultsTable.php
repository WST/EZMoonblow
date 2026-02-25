<?php

namespace Izzy\Web\Tables;

use Izzy\Web\Viewers\TableViewer;

class BacktestResultsTable
{
	public static function create(): TableViewer {
		$table = new TableViewer(['striped' => false, 'hover' => true]);

		// Backtest Id column.
		$table->insertIntegerColumn('id', 'ID', [
			'align' => 'center',
			'width' => '1%'
		]);

		// Backtest date and time.
		$table->insertDateColumn('createdAt', 'Date', ['width' => '130px']);

		// Ticker in EZMoonblow.
		$table->insertTextColumn('ticker', 'Pair', [
			'bold' => true,
			'align' => 'center',
			'headerAlign' => 'center']
		);

		$table->insertTextColumn('exchangeName', 'EX', ['headerAlign' => 'center']);
		$table->insertMarketTypeColumn('marketType', 'Type', ['headerAlign' => 'center']);
		$table->insertTextColumn('timeframe', 'TF', ['headerAlign' => 'center']);
		$table->insertTextColumn('strategy', 'Strategy', ['headerAlign' => 'center']);
		$table->insertNumberColumn('simDurationDays', 'Days', ['decimals' => 0, 'headerAlign' => 'center']);
		$table->insertCustomColumn('balance', 'Balance', fn($v, $row) =>
			number_format((float)($row['initialBalance'] ?? 0), 2) . ' &rarr; ' . number_format((float)($row['finalBalance'] ?? 0), 2),
		['headerAlign' => 'center']);
		$table->insertPnlColumn('pnl', 'PnL', ['percentKey' => 'pnlPercent', 'headerAlign' => 'center']);
		$table->insertIntegerColumn('tradesFinished', 'Trades', ['headerAlign' => 'center']);
		$table->insertPercentColumn('winRate', 'Win %', ['decimals' => 1, 'headerAlign' => 'center']);
		$table->insertCustomColumn('sharpe', 'Sharpe', fn($v) =>
			$v !== null ? number_format((float)$v, 2) : '—',
		['headerAlign' => 'center']);
		$table->insertBadgeColumn('liquidated', 'Status', fn($v) =>
			$v ? ['label' => 'Liquidated', 'variant' => 'danger', 'headerAlign' => 'center'] : ['label' => 'OK', 'variant' => 'success', 'headerAlign' => 'center']
		);
		$table->insertBadgeColumn('mode', 'Mode', fn($v) =>
			$v === 'Auto' ? ['label' => 'Auto', 'variant' => 'info', 'headerAlign' => 'center'] : ['label' => 'Manual', 'variant' => 'secondary', 'headerAlign' => 'center']
		);

		return $table;
	}
}
