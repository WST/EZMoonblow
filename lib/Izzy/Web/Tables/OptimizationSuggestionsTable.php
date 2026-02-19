<?php

namespace Izzy\Web\Tables;

use Izzy\Web\Viewers\TableViewer;

class OptimizationSuggestionsTable
{
	public static function create(): TableViewer {
		$table = new TableViewer(['striped' => false, 'hover' => true]);

		$table->insertIntegerColumn('id', 'ID', ['width' => '50px']);
		$table->insertDateColumn('createdAt', 'Date', ['width' => '130px']);
		$table->insertTextColumn('ticker', 'Pair', ['bold' => true]);
		$table->insertTextColumn('timeframe', 'TF');
		$table->insertTextColumn('strategy', 'Strategy');
		$table->insertTextColumn('mutatedParam', 'Parameter');
		$table->insertTextColumn('originalValue', 'Original');
		$table->insertTextColumn('mutatedValue', 'Suggested');
		$table->insertPnlColumn('baselinePnlPercent', 'Baseline PnL%', []);
		$table->insertPnlColumn('mutatedPnlPercent', 'New PnL%', []);
		$table->insertCustomColumn('improvementPercent', 'Improvement', fn($v) =>
			'<span style="color:#22c55e;font-weight:bold;">+' . number_format((float)$v, 2) . '%</span>'
		);
		$table->insertBadgeColumn('status', 'Status', fn($v) => match ($v) {
			'Applied' => ['label' => 'Applied', 'variant' => 'success'],
			'Dismissed' => ['label' => 'Dismissed', 'variant' => 'secondary'],
			default => ['label' => 'New', 'variant' => 'info'],
		});

		return $table;
	}
}
