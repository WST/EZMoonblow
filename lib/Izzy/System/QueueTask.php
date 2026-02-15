<?php

namespace Izzy\System;

use Izzy\Enums\CandleStorageEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\TaskRecipientEnum;
use Izzy\Enums\TaskStatusEnum;
use Izzy\Enums\TaskTypeEnum;
use Izzy\Financial\Market;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;
use Izzy\RealApplications\Analyzer;
use Izzy\RealApplications\Notifier;
use Izzy\System\Database\Database;
use Izzy\System\Database\ORM\SurrogatePKDatabaseRecord;

class QueueTask extends SurrogatePKDatabaseRecord
{
	const FId = 'task_id';
	const FRecipient = 'task_recipient';
	const FSender = 'task_sender';
	const FType = 'task_type';
	const FStatus = 'task_status';
	const FCreatedAt = 'task_created_at';
	const FAttributes = 'task_attributes';

	public function __construct(Database $database, array $row) {
		parent::__construct($database, $row, self::FId);
	}

	/**
	 * Name of the database table to store queue tasks.
	 * @return string
	 */
	public static function getTableName(): string {
		return 'tasks';
	}

	public static function addTelegramNotification_newPosition(Market $sender, PositionDirectionEnum $direction): void {
		$database = $sender->getDatabase();
		$appName = Notifier::getApplicationName();

		// Check if such a task already exists for this market.
		if (self::taskAlreadyExists($database, $sender, $appName, TaskTypeEnum::TELEGRAM_WANT_NEW_POSITION)) {
			return;
		}

		// New attributes.
		$attributes = $sender->getTaskMarketAttributes();
		$attributes['direction'] = $direction->value;

		// New row.
		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FSender => TaskRecipientEnum::TRADER->value,
			self::FType => TaskTypeEnum::TELEGRAM_WANT_NEW_POSITION->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];

		// New task.
		$task = new self($database, $row);

		// Saving the newly created task.
		$task->save();
	}

	/**
	 * Notify Telegram that a new position has been opened.
	 *
	 * @param IMarket $market     Market the position was opened on.
	 * @param IStoredPosition $position  The opened position.
	 */
	public static function addTelegramNotification_positionOpened(IMarket $market, IStoredPosition $position): void {
		$database = $market->getDatabase();
		$appName = Notifier::getApplicationName();

		$attributes = self::buildPositionAttributes($market, $position);

		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FSender => TaskRecipientEnum::TRADER->value,
			self::FType => TaskTypeEnum::TELEGRAM_POSITION_OPENED->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];

		$task = new self($database, $row);
		$task->save();
	}

	/**
	 * Notify Telegram that a position has been closed (TP, SL, or other reason).
	 *
	 * @param IMarket $market           Market the position was on.
	 * @param IStoredPosition $position  The closed position (with final PnL data).
	 */
	public static function addTelegramNotification_positionClosed(IMarket $market, IStoredPosition $position): void {
		$database = $market->getDatabase();
		$appName = Notifier::getApplicationName();

		$attributes = self::buildPositionAttributes($market, $position);
		$attributes['pnl'] = $position->getUnrealizedPnL()->getAmount();
		$attributes['finishReason'] = $position->getFinishReason()?->value ?? 'unknown';
		$attributes['duration'] = $position->getFinishedAt() - $position->getCreatedAt();

		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FSender => TaskRecipientEnum::TRADER->value,
			self::FType => TaskTypeEnum::TELEGRAM_POSITION_CLOSED->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];

		$task = new self($database, $row);
		$task->save();
	}

	/**
	 * Notify Telegram that Breakeven Lock has been executed on a position.
	 *
	 * @param IMarket $market           Market the position is on.
	 * @param IStoredPosition $position  The position after BL execution.
	 * @param float $closedVolume        Volume that was partially closed.
	 * @param float $lockedProfit        Profit locked by the partial close.
	 */
	public static function addTelegramNotification_breakevenLock(
		IMarket $market,
		IStoredPosition $position,
		float $closedVolume,
		float $lockedProfit,
	): void {
		$database = $market->getDatabase();
		$appName = Notifier::getApplicationName();

		$attributes = self::buildPositionAttributes($market, $position);
		$attributes['closedVolume'] = $closedVolume;
		$attributes['lockedProfit'] = $lockedProfit;

		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FSender => TaskRecipientEnum::TRADER->value,
			self::FType => TaskTypeEnum::TELEGRAM_BREAKEVEN_LOCK->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];

		$task = new self($database, $row);
		$task->save();
	}

	/**
	 * Build common position attributes for Telegram notification tasks.
	 */
	private static function buildPositionAttributes(IMarket $market, IStoredPosition $position): array {
		return [
			'pair' => $market->getTicker(),
			'timeframe' => $market->getPair()->getTimeframe()->value,
			'marketType' => $market->getPair()->getMarketType()->value,
			'exchange' => $market->getExchange()->getName(),
			'direction' => $position->getDirection()->value,
			'entryPrice' => $position->getAverageEntryPrice()->getAmount(),
			'volume' => $position->getVolume()->getAmount(),
			'currentPrice' => $position->getCurrentPrice()->getAmount(),
			'slPrice' => $position->getStopLossPrice()?->getAmount(),
			'tpPrice' => $position->getTakeProfitPrice()?->getAmount(),
			'leverage' => $market->getPair()->getLeverage(),
		];
	}

	public static function updateChart(Market $sender): void {
		Logger::getLogger()->debug("Scheduling chart update for $sender");
		$database = $sender->getDatabase();
		$appName = Analyzer::getApplicationName();

		// Check if such a task already exists for this market.
		if (self::taskAlreadyExists($database, $sender, $appName, TaskTypeEnum::DRAW_CANDLESTICK_CHART)) {
			return;
		}

		// New attributes.
		$attributes = $sender->getTaskMarketAttributes();

		// New row.
		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FSender => TaskRecipientEnum::TRADER->value,
			self::FType => TaskTypeEnum::DRAW_CANDLESTICK_CHART->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];

		// New task.
		$task = new self($database, $row);

		// Saving the newly created task.
		$task->save();
	}

	/**
	 * Schedule a candle loading task for the Analyzer.
	 *
	 * @param Database $database Database instance.
	 * @param string $exchange Exchange name.
	 * @param string $ticker Pair ticker (e.g. "SOL/USDT").
	 * @param string $marketType Market type value ('spot' or 'futures').
	 * @param string $timeframe Timeframe value (e.g. "4h").
	 * @param int $startTime Start timestamp (seconds).
	 * @param int $endTime End timestamp (seconds).
	 * @param CandleStorageEnum $storage Target storage table.
	 */
	public static function loadCandles(
		Database $database,
		string $exchange,
		string $ticker,
		string $marketType,
		string $timeframe,
		int $startTime,
		int $endTime,
		CandleStorageEnum $storage = CandleStorageEnum::RUNTIME,
	): void {
		$appName = Analyzer::getApplicationName();
		$attributes = [
			'exchange' => $exchange,
			'pair' => $ticker,
			'marketType' => $marketType,
			'timeframe' => $timeframe,
			'startTime' => $startTime,
			'endTime' => $endTime,
			'storage' => $storage->value,
		];

		// Deduplicate: check if an identical task already exists.
		if (self::loadCandlesTaskAlreadyExists($database, $appName, $attributes)) {
			return;
		}

		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FSender => TaskRecipientEnum::TRADER->value,
			self::FType => TaskTypeEnum::LOAD_CANDLES->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];

		$task = new self($database, $row);
		$task->save();
	}

	/**
	 * Check whether a LOAD_CANDLES task with identical attributes already exists.
	 *
	 * @param Database $database Database instance.
	 * @param string $appName Recipient application name.
	 * @param array $attributes Task attributes to match.
	 * @return bool True if a matching task exists.
	 */
	private static function loadCandlesTaskAlreadyExists(Database $database, string $appName, array $attributes): bool {
		$existingTasks = $database->selectAllRows(
			self::getTableName(),
			'*',
			[self::FRecipient => $appName, self::FType => TaskTypeEnum::LOAD_CANDLES->value],
		);

		foreach ($existingTasks as $task) {
			$taskAttributes = json_decode($task[self::FAttributes], true);
			if (
				($taskAttributes['exchange'] ?? '') === $attributes['exchange']
				&& ($taskAttributes['pair'] ?? '') === $attributes['pair']
				&& ($taskAttributes['marketType'] ?? '') === $attributes['marketType']
				&& ($taskAttributes['timeframe'] ?? '') === $attributes['timeframe']
				&& ($taskAttributes['startTime'] ?? 0) === $attributes['startTime']
				&& ($taskAttributes['endTime'] ?? 0) === $attributes['endTime']
				&& ($taskAttributes['storage'] ?? '') === $attributes['storage']
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether a task of given type already exists for such Market (based on attributes).
	 * @param Database $database
	 * @param Market $sender
	 * @param string $appName
	 * @param TaskTypeEnum $taskType
	 * @return bool
	 */
	private static function taskAlreadyExists(
		Database $database,
		Market $sender,
		string $appName,
		TaskTypeEnum $taskType
	): bool {
		$existingTasks = $database->selectAllRows(
			QueueTask::getTableName(),
			'*',
			[self::FRecipient => $appName, self::FType => $taskType->value],
		);

		// If the attributes match, the task already exists then.
		foreach ($existingTasks as $task) {
			$taskAttributes = json_decode($task[self::FAttributes], true);
			if ($sender->taskMarketAttributesMatch($taskAttributes)) {
				return true;
			}
		}

		return false;
	}

	public function getStatus(): TaskStatusEnum {
		return TaskStatusEnum::from($this->row[self::FStatus]);
	}

	public function getRecipient(): TaskRecipientEnum {
		return TaskRecipientEnum::from($this->row[self::FRecipient]);
	}

	public function getSender(): ?TaskRecipientEnum {
		$value = $this->row[self::FSender] ?? null;
		return $value ? TaskRecipientEnum::from($value) : null;
	}

	public function getType(): TaskTypeEnum {
		return TaskTypeEnum::from($this->row[self::FType]);
	}

	public function getAttributes(): array {
		return json_decode($this->row[self::FAttributes], true);
	}

	public function setAttribute(string $key, string $value): void {
		$currentAttributes = $this->getAttributes();
		$currentAttributes[$key] = $value;
		$this->row[self::FAttributes] = json_encode($currentAttributes);
	}

	public function getCreatedAt(): int {
		return intval($this->row[self::FCreatedAt]);
	}

	public function setStatus(TaskStatusEnum $newStatus): void {
		$this->row[self::FStatus] = $newStatus->value;
	}

	public function isOlderThan(int $seconds): bool {
		$now = time();
		$limit = $now - $seconds;
		return ($this->row[self::FCreatedAt] < $limit);
	}
}
