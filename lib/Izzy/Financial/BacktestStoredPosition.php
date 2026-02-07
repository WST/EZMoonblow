<?php

namespace Izzy\Financial;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\System\Database\Database;

/**
 * Stored position for backtest runs. Uses table backtest_positions.
 */
class BacktestStoredPosition extends StoredPosition
{
	public static function getTableName(): string {
		return 'backtest_positions';
	}

	/**
	 * @inheritDoc
	 */
	public static function create(
		IMarket $market,
		Money $volume,
		PositionDirectionEnum $direction,
		Money $entryPrice,
		Money $currentPrice,
		PositionStatusEnum $status,
		string $exchangePositionId
	): static {
		$now = time();
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
