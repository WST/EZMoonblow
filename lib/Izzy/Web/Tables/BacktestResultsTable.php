<?php

namespace Izzy\Web\Tables;

use Izzy\Web\Viewers\TableViewer;

class BacktestResultsTable
{
	public static function create(): TableViewer {
		$table = new TableViewer(['striped' => false, 'hover' => true]);

		$table->insertIntegerColumn('id', 'ID', ['width' => '50px']);
		$table->insertDateColumn('createdAt', 'Date', ['width' => '130px']);
		$table->insertTextColumn('ticker', 'Pair', ['bold' => true]);
		$table->insertTextColumn('exchangeName', 'EX');
		$table->insertMarketTypeColumn('marketType', 'Type');
		$table->insertTextColumn('timeframe', 'TF');
		$table->insertTextColumn('strategy', 'Strategy');
		$table->insertNumberColumn('simDurationDays', 'Days', ['decimals' => 0]);
		$table->insertCustomColumn('balance', 'Balance', fn($v, $row) =>
			number_format((float)($row['initialBalance'] ?? 0), 2) . ' &rarr; ' . number_format((float)($row['finalBalance'] ?? 0), 2)
		);
		$table->insertPnlColumn('pnl', 'PnL', ['percentKey' => 'pnlPercent']);
		$table->insertIntegerColumn('tradesFinished', 'Trades');
		$table->insertPercentColumn('winRate', 'Win %', ['decimals' => 1]);
		$table->insertCustomColumn('sharpe', 'Sharpe', fn($v) =>
			$v !== null ? number_format((float)$v, 2) : '—'
		);
		$table->insertBadgeColumn('liquidated', 'Status', fn($v) =>
			$v ? ['label' => 'Liquidated', 'variant' => 'danger'] : ['label' => 'OK', 'variant' => 'success']
		);
		$table->insertBadgeColumn('mode', 'Mode', fn($v) =>
			$v === 'Auto' ? ['label' => 'Auto', 'variant' => 'info'] : ['label' => 'Manual', 'variant' => 'secondary']
		);

		return $table;
	}
}
