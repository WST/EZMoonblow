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
		
		if ($status->isPending()) {
			$ticker = $attributes['pair'];
			$timeframe = TimeFrameEnum::from($attributes['timeframe']);
			$exchangeName = $attributes['exchange'];
			$marketType = MarketTypeEnum::from($attributes['marketType']);
			$direction = PositionDirectionEnum::from($attributes['direction']);
			
			// Connect to Exchange and draw a chart for our message.
			$pair = new Pair($ticker, $timeframe, $exchangeName, $marketType);
			$exchange = $this->configuration->connectExchange($this, $exchangeName);
			if (!$exchange) return;
			$market = $exchange->createMarket($pair);
			$chartFilename = $market->drawChart();
			
			$message = "Strategy wants to open a $direction->value position for $pair ($marketType->value, $timeframe->value) on $exchangeName";
			$this->logger->info($message);
			$this->sendTelegramNotification($message, $chartFilename);

			$task->setStatus(TaskStatusEnum::INPROGRESS);
			$task->save();
		}
		
		if ($status->isInProgress() && $task->isOlderThan(600)) {
			$task->remove();
		}
	}

	private function sendTelegramNotification(string $message, ?string $photoPath = null): void {
		if ($photoPath && file_exists($photoPath) && is_readable($photoPath)) {
			// Message with an image.
			$this->telegram->sendPhoto([
				'chat_id' => $this->telegramChatId,
				'photo' => fopen($photoPath, 'r'),
				'caption' => $message,
				'parse_mode' => 'HTML'
			]);
		} else {
			// Text only.
			$this->telegram->sendMessage([
				'chat_id' => $this->telegramChatId,
				'parse_mode' => 'HTML',
				'text' => $message
			]);
		}
	}
}
