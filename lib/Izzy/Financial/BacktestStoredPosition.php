<?php

namespace Izzy\Financial;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\System\Database\Database;

/**
 * Stored position for backtest runs.
 *
 * Table name is dynamic: `backtest_positions` by default, or
 * `backtest_positions_{suffix}` when a suffix is set via setTableSuffix().
 * This allows multiple backtests to run in parallel without conflicting.
 */
class BacktestStoredPosition extends StoredPosition
{
	private static ?string $tableSuffix = null;

	/**
	 * Set a unique suffix for the backtest positions table.
	 * Must be called before creating the table and before any DB operations.
	 */
	public static function setTableSuffix(string $suffix): void {
		self::$tableSuffix = $suffix;
	}

	/**
	 * Reset the table suffix back to default (no suffix).
	 */
	public static function resetTableSuffix(): void {
		self::$tableSuffix = null;
	}

	public static function getTableName(): string {
		if (self::$tableSuffix !== null) {
			return 'backtest_positions_' . self::$tableSuffix;
		}
		return 'backtest_positions';
	}

	/**
	 * @inheritDoc
	 * @param int|null $createdAt Unix timestamp for position_created_at (for backtest: simulation time; omit for real time).
	 */
	public static function create(
		IMarket $market,
		Money $volume,
		PositionDirectionEnum $direction,
		Money $entryPrice,
		Money $currentPrice,
		PositionStatusEnum $status,
		string $exchangePositionId,
		?int $createdAt = null
	): static {
		$now = $createdAt ?? time();
		$row = [
			self::FExchangeName => $market->getExchange()->getName(),
			self::FTicker => $market->getTicker(),
			self::FMarketType => $market->getMarketType()->toString(),
			self::FDirection => $direction->toString(),
			self::FInitialEntryPrice => $entryPrice->getAmount(),
			self::FAverageEntryPrice => $entryPrice->getAmount(),
			self::FCurrentPrice => $currentPrice->getAmount(),
			self::FVolume => $volume->getAmount(),
			self::FBaseCurrency => $market->getPair()->getBaseCurrency(),
			self::FQuoteCurrency => $market->getPair()->getQuoteCurrency(),
			self::FStatus => $status->toString(),
			self::FIdOnExchange => $exchangePositionId,
			self::FEntryOrderIdOnExchange => $exchangePositionId,
			self::FCreatedAt => $now,
			self::FUpdatedAt => $now,
			self::FExpectedProfitPercent => 0.0,
		];
		return new self($market->getDatabase(), $row);
	}
}
