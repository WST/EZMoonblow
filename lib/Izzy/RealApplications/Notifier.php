<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
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

	public function run() {
		while (true) {
			$response = $this->telegram->sendMessage([
				'chat_id' => $this->telegramChatId,
				'text' => 'Hello World'
			]);
			var_dump($response);
			sleep(60);
		}
	}
}
