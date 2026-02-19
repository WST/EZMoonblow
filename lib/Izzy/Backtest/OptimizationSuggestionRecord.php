<?php

namespace Izzy\Backtest;

use Izzy\System\Database\Database;
use Izzy\System\Database\ORM\SurrogatePKDatabaseRecord;

/**
 * Persisted optimization suggestion record.
 * Stores each improvement found by the Optimizer daemon.
 */
class OptimizationSuggestionRecord extends SurrogatePKDatabaseRecord
{
	const string FId = 'os_id';
	const string FTicker = 'os_ticker';
	const string FExchangeName = 'os_exchange_name';
	const string FMarketType = 'os_market_type';
	const string FTimeframe = 'os_timeframe';
	const string FStrategy = 'os_strategy';
	const string FMutatedParam = 'os_mutated_param';
	const string FOriginalValue = 'os_original_value';
	const string FMutatedValue = 'os_mutated_value';
	const string FBaselinePnlPercent = 'os_baseline_pnl_percent';
	const string FMutatedPnlPercent = 'os_mutated_pnl_percent';
	const string FImprovementPercent = 'os_improvement_percent';
	const string FBaselineBacktestId = 'os_baseline_backtest_id';
	const string FMutatedBacktestId = 'os_mutated_backtest_id';
	const string FSuggestedXml = 'os_suggested_xml';
	const string FStatus = 'os_status';
	const string FCreatedAt = 'os_created_at';

	const string STATUS_NEW = 'New';
	const string STATUS_APPLIED = 'Applied';
	const string STATUS_DISMISSED = 'Dismissed';

	public function __construct(Database $database, array $row) {
		parent::__construct($database, $row, self::FId);
	}

	public static function getTableName(): string {
		return 'optimization_suggestions';
	}

	/**
	 * Persist a new optimization suggestion.
	 */
	public static function saveFromData(
		Database $database,
		string $ticker,
		string $exchangeName,
		string $marketType,
		string $timeframe,
		string $strategy,
		string $mutatedParam,
		string $originalValue,
		string $mutatedValue,
		float $baselinePnlPercent,
		float $mutatedPnlPercent,
		int $baselineBacktestId,
		int $mutatedBacktestId,
		?string $suggestedXml = null,
	): ?int {
		$row = [
			self::FTicker => $ticker,
			self::FExchangeName => $exchangeName,
			self::FMarketType => $marketType,
			self::FTimeframe => $timeframe,
			self::FStrategy => $strategy,
			self::FMutatedParam => $mutatedParam,
			self::FOriginalValue => $originalValue,
			self::FMutatedValue => $mutatedValue,
			self::FBaselinePnlPercent => $baselinePnlPercent,
			self::FMutatedPnlPercent => $mutatedPnlPercent,
			self::FImprovementPercent => $mutatedPnlPercent - $baselinePnlPercent,
			self::FBaselineBacktestId => $baselineBacktestId,
			self::FMutatedBacktestId => $mutatedBacktestId,
			self::FSuggestedXml => $suggestedXml,
			self::FStatus => self::STATUS_NEW,
			self::FCreatedAt => time(),
		];

		$record = new self($database, $row);
		$savedId = $record->save();
		return $savedId !== false ? (int) $savedId : null;
	}

	/**
	 * Load all suggestions, newest first.
	 *
	 * @return self[]
	 */
	public static function loadAll(Database $database): array {
		$rows = $database->selectAllRows(
			self::getTableName(),
			'*',
			[],
			self::FCreatedAt . ' DESC',
		);
		return array_map(fn(array $row) => new self($database, $row), $rows);
	}

	/**
	 * Load filtered suggestions with ordering and pagination.
	 *
	 * @return self[]
	 */
	public static function loadFiltered(
		Database $database,
		string|array $where = [],
		string $order = '',
		?int $limit = null,
		?int $offset = null,
	): array {
		if (empty($order)) {
			$order = self::FCreatedAt . ' DESC';
		}
		$rows = $database->selectAllRows(
			self::getTableName(),
			'*',
			$where,
			$order,
			$limit,
			$offset,
		);
		return array_map(fn(array $row) => new self($database, $row), $rows);
	}

	/**
	 * Count filtered results.
	 */
	public static function countFiltered(Database $database, string|array $where = []): int {
		return $database->countRows(self::getTableName(), $where);
	}

	/**
	 * Load a single suggestion by ID.
	 */
	public static function loadById(Database $database, int $id): ?self {
		$rows = $database->selectAllRows(self::getTableName(), '*', [self::FId => $id], '', 1);
		if (empty($rows)) {
			return null;
		}
		return new self($database, $rows[0]);
	}

	/**
	 * Update the status of this suggestion.
	 */
	public function setStatus(string $status): void {
		$this->row[self::FStatus] = $status;
	}

	// ---- Getters ----

	public function getTicker(): string {
		return $this->row[self::FTicker];
	}

	public function getExchangeName(): string {
		return $this->row[self::FExchangeName];
	}

	public function getMarketType(): string {
		return $this->row[self::FMarketType];
	}

	public function getTimeframe(): string {
		return $this->row[self::FTimeframe];
	}

	public function getStrategy(): string {
		return $this->row[self::FStrategy];
	}

	public function getMutatedParam(): string {
		return $this->row[self::FMutatedParam];
	}

	public function getOriginalValue(): string {
		return $this->row[self::FOriginalValue];
	}

	public function getMutatedValue(): string {
		return $this->row[self::FMutatedValue];
	}

	public function getBaselinePnlPercent(): float {
		return (float) $this->row[self::FBaselinePnlPercent];
	}

	public function getMutatedPnlPercent(): float {
		return (float) $this->row[self::FMutatedPnlPercent];
	}

	public function getImprovementPercent(): float {
		return (float) $this->row[self::FImprovementPercent];
	}

	public function getBaselineBacktestId(): int {
		return (int) $this->row[self::FBaselineBacktestId];
	}

	public function getMutatedBacktestId(): int {
		return (int) $this->row[self::FMutatedBacktestId];
	}

	public function getSuggestedXml(): ?string {
		return $this->row[self::FSuggestedXml] ?? null;
	}

	public function getStatus(): string {
		return $this->row[self::FStatus];
	}

	public function getCreatedAt(): int {
		return (int) $this->row[self::FCreatedAt];
	}

	/**
	 * Convert to array for template rendering.
	 */
	public function toArray(): array {
		return [
			'id' => $this->getId(),
			'ticker' => $this->getTicker(),
			'exchangeName' => $this->getExchangeName(),
			'marketType' => $this->getMarketType(),
			'timeframe' => $this->getTimeframe(),
			'strategy' => $this->getStrategy(),
			'mutatedParam' => $this->getMutatedParam(),
			'originalValue' => $this->getOriginalValue(),
			'mutatedValue' => $this->getMutatedValue(),
			'baselinePnlPercent' => $this->getBaselinePnlPercent(),
			'mutatedPnlPercent' => $this->getMutatedPnlPercent(),
			'improvementPercent' => $this->getImprovementPercent(),
			'baselineBacktestId' => $this->getBaselineBacktestId(),
			'mutatedBacktestId' => $this->getMutatedBacktestId(),
			'suggestedXml' => $this->getSuggestedXml(),
			'status' => $this->getStatus(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
