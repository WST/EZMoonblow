<?php

namespace Izzy\Web\Tables;

use Izzy\Web\Viewers\TableViewer;

class CandlesTable
{
	public static function create(): TableViewer {
		$table = new TableViewer(['striped' => false, 'hover' => true]);

		$table->insertTextColumn('exchange', 'Exchange', ['headerAlign' => 'center']);
		$table->insertPairColumn('ticker', 'Pair', ['headerAlign' => 'center']);
		$table->insertMarketTypeColumn('marketType', 'Market Type', ['headerAlign' => 'center']);
		$table->insertTextColumn('timeframe', 'TF', ['headerAlign' => 'center', 'align' => 'center']);
		$table->insertDateColumn('from', 'From', ['headerAlign' => 'center', 'dateFormat' => 'Y-m-d']);
		$table->insertDateColumn('to', 'To', ['headerAlign' => 'center', 'dateFormat' => 'Y-m-d']);
		$table->insertCustomColumn('count', 'Candles', fn($v) =>
			number_format((int)$v),
		['headerAlign' => 'center', 'align' => 'right']);

		return $table;
	}
}
