<?php

namespace Izzy\Web\Tables;

use Izzy\Web\Viewers\TableViewer;

/**
 * Table definition for the Positions page.
 */
class PositionsTable
{
	public static function create(): TableViewer {
		$table = new TableViewer(['striped' => false, 'hover' => true]);

		$table->insertIntegerColumn('positionId', 'ID', ['align' => 'center', 'width' => '1%']);
		$table->insertDirectionColumn('direction', "\u{21C5}", ['headerAlign' => 'center']);
		$table->insertMarketTypeColumn('marketType', 'Type', ['headerAlign' => 'center']);
		$table->insertTextColumn('exchangeName', 'EX', ['headerAlign' => 'center']);
		$table->insertPairColumn('ticker', 'Pair', ['headerAlign' => 'center']);
		$table->insertMoneyColumn('volume', 'Volume', ['headerAlign' => 'center']);
		$table->insertMoneyColumn('averageEntryPrice', 'Avg Entry', ['headerAlign' => 'center']);
		$table->insertMoneyColumn('currentPrice', 'Current Price', ['headerAlign' => 'center']);
		$table->insertMoneyColumn('stopLossPrice', 'SL Price', ['headerAlign' => 'center']);
		$table->insertMoneyColumn('takeProfitPrice', 'TP Price', ['headerAlign' => 'center']);
		$table->insertPnlColumn('unrealizedPnL', 'PnL', [
			'percentKey' => 'unrealizedPnLPercent',
			'headerAlign' => 'center',
		]);
		$table->insertPositionStatusColumn('status', 'Status', [
			'breakevenLockedKey' => 'breakevenLocked',
			'headerAlign' => 'center',
		]);
		$table->insertDateColumn('createdAt', 'Created', ['headerAlign' => 'center']);
		$table->insertDateColumn('updatedAt', 'Updated', ['headerAlign' => 'center']);
		$table->insertDateColumn('finishedAt', 'Finished', ['headerAlign' => 'center']);

		return $table;
	}
}
