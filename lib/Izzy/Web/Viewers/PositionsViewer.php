<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Enums\PositionStatusEnum;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Viewer for the Open Positions page.
 * Displays trading positions from the database.
 */
class PositionsViewer extends PageViewer {
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response): Response {
		$database = $this->webApp->getDatabase();

		// Get position statistics.
		$stats = $this->getPositionStats($database);

		// Get all positions (open first, then others).
		$positions = $this->getPositions($database);

		$body = $this->webApp->getTwig()->render('positions.htt', [
			'menu' => $this->menu,
			'stats' => $stats,
			'positions' => $positions,
		]);

		$response->getBody()->write($body);
		return $response;
	}

	/**
	 * Get position statistics by status.
	 *
	 * @param mixed $database Database connection.
	 * @return array Position statistics.
	 */
	private function getPositionStats($database): array {
		$stats = [
			'total' => 0,
			'open' => 0,
			'pending' => 0,
			'finished' => 0,
			'cancelled' => 0,
			'error' => 0,
		];

		$rows = $database->selectAllRows('positions');
		foreach ($rows as $row) {
			$stats['total']++;
			$status = $row['position_status'];

			switch ($status) {
				case PositionStatusEnum::OPEN->value:
					$stats['open']++;
					break;
				case PositionStatusEnum::PENDING->value:
					$stats['pending']++;
					break;
				case PositionStatusEnum::FINISHED->value:
					$stats['finished']++;
					break;
				case PositionStatusEnum::CANCELED->value:
					$stats['cancelled']++;
					break;
				case PositionStatusEnum::ERROR->value:
					$stats['error']++;
					break;
			}
		}

		return $stats;
	}

	/**
	 * Get all positions for display.
	 *
	 * @param mixed $database Database connection.
	 * @return array Positions with calculated fields.
	 */
	private function getPositions($database): array {
		// Order: open positions first, then pending, then others by updated_at desc.
		$rows = $database->selectAllRows(
			'positions',
			'*',
			[],
			"FIELD(position_status, 'OPEN', 'PENDING', 'FINISHED', 'CANCELED', 'ERROR'), position_updated_at DESC"
		);

		$positions = [];
		foreach ($rows as $row) {
			$entryPrice = (float)$row['position_entry_price'];
			$currentPrice = (float)$row['position_current_price'];
			$volume = (float)$row['position_volume'];
			$direction = $row['position_direction'];

			// Calculate PnL percentage.
			$pnlPercent = 0;
			if ($entryPrice > 0) {
				if ($direction === 'LONG') {
					$pnlPercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;
				} else {
					$pnlPercent = (($entryPrice - $currentPrice) / $entryPrice) * 100;
				}
			}

			// Calculate unrealized PnL in quote currency.
			$pnlAmount = 0;
			if ($entryPrice > 0) {
				if ($direction === 'LONG') {
					$pnlAmount = ($currentPrice - $entryPrice) * $volume;
				} else {
					$pnlAmount = ($entryPrice - $currentPrice) * $volume;
				}
			}

			// Timestamps are stored as INT (Unix timestamp).
			$createdAt = (int)($row['position_created_at'] ?? 0);
			$updatedAt = (int)($row['position_updated_at'] ?? 0);

			$positions[] = [
				'id' => $row['position_id'],
				'exchange' => $row['position_exchange_name'],
				'ticker' => $row['position_ticker'],
				'market_type' => $row['position_market_type'],
				'direction' => $direction,
				'status' => $row['position_status'],
				'volume' => $volume,
				'base_currency' => $row['position_base_currency'],
				'quote_currency' => $row['position_quote_currency'],
				'entry_price' => $entryPrice,
				'current_price' => $currentPrice,
				'pnl_percent' => $pnlPercent,
				'pnl_amount' => $pnlAmount,
				'created_at' => $createdAt,
				'created_at_formatted' => $createdAt > 0 ? date('Y-m-d H:i', $createdAt) : '-',
				'updated_at' => $updatedAt,
				'updated_at_formatted' => $updatedAt > 0 ? date('Y-m-d H:i', $updatedAt) : '-',
			];
		}

		return $positions;
	}
}
