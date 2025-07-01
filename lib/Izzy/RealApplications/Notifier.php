<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\TaskStatusEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Pair;
use Izzy\System\QueueTask;
use Telegram\Bot\Api as TelegramApi;

class Notifier extends ConsoleApplication
{
	private string $telegramToken;
	private int $telegramChatId;
	private TelegramApi $telegram;
	
	public function __construct() {
		parent::__construct();
		
		$this->telegramToken = $this->configuration->getTelegramToken();
		$this->telegramChatId = $this->configuration->getTelegramChatId();
		
		// Create Telegram SDK instance.
		$this->telegram = new TelegramApi($this->telegramToken);
	}

	protected function processTask(QueueTask $task): void {
		$taskType = $task->getType();
		$taskStatus = $task->getStatus();

		// Task is an intent to open a new position.
		if ($taskType->isTelegramWantNewPosition()) {
			$this->handleWantNewPositionNotification($task);
			return;
		}

		$this->logger->warning("Got an unknown task type: $taskType->value");
	}

	public function run() {
		while (true) {
			$this->processTasks();
			sleep(10);
		}
	}

	private function handleWantNewPositionNotification(QueueTask $task): void {
		$status = $task->getStatus();
		$attributes = $task->getAttributes();
		$notificationSent = $status->isInProgress();
		
		if (!$notificationSent) {
			$ticker = $attributes['pair'];
			$timeframe = TimeFrameEnum::from($attributes['timeframe']);
			$exchangeName = $attributes['exchange'];
			$marketType = MarketTypeEnum::from($attributes['marketType']);
			$direction = PositionDirectionEnum::from($attributes['direction']);
			$pair = new Pair($ticker, $timeframe, $exchangeName, $marketType);
			$message = "Strategy wants to open a $direction->value position for $pair ($marketType->value, $timeframe->value) on $exchangeName";
			$this->logger->info($message);
			$this->sendTelegramNotification($message);
		}
		
		// Remove the task only if it’s outdated. If not, just mark that notification was sent.
		$limit = time() - 600; // 5 minutes
		if ($task->getCreatedAt() < $limit) {
			$task->remove();
		} else {
			if ($status->isPending()) {
				$task->setStatus(TaskStatusEnum::INPROGRESS);
				$task->save();
			}
		}
	}

	private function sendTelegramNotification(string $message): void {
		$this->telegram->sendMessage(['chat_id' => $this->telegramChatId, 'parse_mode' => 'HTML', 'text' => $message]);
	}
}
