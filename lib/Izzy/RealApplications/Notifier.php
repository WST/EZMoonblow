<?php

namespace Izzy\RealApplications;

use Exception;
use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionFinishReasonEnum;
use Izzy\Enums\TaskStatusEnum;
use Izzy\Enums\TaskTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Pair;
use Izzy\System\QueueTask;
use Telegram\Bot\Api as TelegramApi;

class Notifier extends ConsoleApplication
{
	private string $telegramToken;
	private int $telegramChatId;
	private TelegramApi $telegram;
	private array $exchanges;
	private int $lastUpdateId = 0;

	public function __construct() {
		parent::__construct();

		$this->telegramToken = $this->configuration->getTelegramToken();
		$this->telegramChatId = $this->configuration->getTelegramChatId();

		// Create Telegram SDK instance.
		$this->telegram = new TelegramApi($this->telegramToken);

		// Load exchanges for bot commands
		$this->exchanges = $this->configuration->connectExchanges($this);
	}

	protected function processTask(QueueTask $task): void {
		$taskType = $task->getType();

		// Task is an intent to open a new position.
		if ($taskType->isTelegramWantNewPosition()) {
			$this->handleWantNewPositionNotification($task);
			return;
		}

		// Position opened notification.
		if ($taskType === TaskTypeEnum::TELEGRAM_POSITION_OPENED) {
			$this->handlePositionOpenedNotification($task);
			return;
		}

		// Position closed notification.
		if ($taskType === TaskTypeEnum::TELEGRAM_POSITION_CLOSED) {
			$this->handlePositionClosedNotification($task);
			return;
		}

		// Breakeven Lock notification.
		if ($taskType === TaskTypeEnum::TELEGRAM_BREAKEVEN_LOCK) {
			$this->handleBreakevenLockNotification($task);
			return;
		}

		$this->logger->warning("Got an unknown task type: $taskType->value");
	}

	public function run(): void {
		$this->logger->info('Starting EZMoonblow Notifier...');

		// Start heartbeat monitoring.
		$this->startHeartbeat();

		while (true) {
			try {
				// Update heartbeat.
				$this->beat();

				// Process tasks first.
				$this->processTasks();

				// Handle incoming messages (includes 5s timeout in long polling).
				$this->handleIncomingMessages();
			} catch (Exception $e) {
				$this->logger->error('Notifier error: '.$e->getMessage());
				$this->interruptibleSleep(60);
			}
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
			if (!$exchange)
				return;
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

	// ------------------------------------------------------------------
	// Position event notifications (Freqtrade-style formatting)
	// ------------------------------------------------------------------

	/**
	 * Handle "Position Opened" notification.
	 * Sends a formatted Telegram message when a new position is opened.
	 */
	private function handlePositionOpenedNotification(QueueTask $task): void {
		$a = $task->getAttributes();

		$direction = strtoupper($a['direction'] ?? '?');
		$dirEmoji = ($direction === 'LONG') ? "\xF0\x9F\x9F\xA2" : "\xF0\x9F\x94\xB4"; // Green/Red circle
		$pair = $a['pair'] ?? '?';
		$exchange = $a['exchange'] ?? '?';
		$timeframe = $a['timeframe'] ?? '?';
		$entryPrice = (float)($a['entryPrice'] ?? 0);
		$volume = (float)($a['volume'] ?? 0);
		$slPrice = isset($a['slPrice']) ? (float)$a['slPrice'] : null;
		$tpPrice = isset($a['tpPrice']) ? (float)$a['tpPrice'] : null;
		$leverage = isset($a['leverage']) ? (float)$a['leverage'] : null;

		$entryStr = $this->formatPrice($entryPrice);
		$notionalValue = $entryPrice * $volume;

		$message = "{$dirEmoji} <b>New {$direction}</b>\n\n";
		$message .= "<b>Pair:</b> {$pair}\n";
		$message .= "<b>Exchange:</b> {$exchange} ({$timeframe})\n";
		if ($leverage !== null && $leverage > 1) {
			$message .= "<b>Leverage:</b> {$leverage}x\n";
		}
		$message .= "\n";
		$message .= "<b>Entry Price:</b> {$entryStr}\n";
		$message .= "<b>Amount:</b> " . $this->formatVolume($volume) . "\n";
		$message .= "<b>Notional:</b> " . number_format($notionalValue, 2) . " USDT\n";
		$message .= "\n";
		if ($slPrice !== null) {
			$slPercent = abs(($slPrice - $entryPrice) / $entryPrice * 100);
			$message .= "\xF0\x9F\x9B\x91 <b>Stop Loss:</b> " . $this->formatPrice($slPrice) . " (-" . number_format($slPercent, 1) . "%)\n";
		}
		if ($tpPrice !== null) {
			$tpPercent = abs(($tpPrice - $entryPrice) / $entryPrice * 100);
			$message .= "\xF0\x9F\x8E\xAF <b>Take Profit:</b> " . $this->formatPrice($tpPrice) . " (+" . number_format($tpPercent, 1) . "%)\n";
		}

		$this->logger->info("Position opened: {$direction} {$pair} @ {$entryStr} on {$exchange}");
		$this->sendTelegramNotification($message);
		$task->remove();
	}

	/**
	 * Handle "Position Closed" notification.
	 * Sends a formatted Telegram message when a position is closed.
	 */
	private function handlePositionClosedNotification(QueueTask $task): void {
		$a = $task->getAttributes();

		$direction = strtoupper($a['direction'] ?? '?');
		$pair = $a['pair'] ?? '?';
		$exchange = $a['exchange'] ?? '?';
		$timeframe = $a['timeframe'] ?? '?';
		$entryPrice = (float)($a['entryPrice'] ?? 0);
		$currentPrice = (float)($a['currentPrice'] ?? 0);
		$pnl = (float)($a['pnl'] ?? 0);
		$finishReason = $a['finishReason'] ?? 'unknown';
		$duration = (int)($a['duration'] ?? 0);
		$leverage = isset($a['leverage']) ? (float)$a['leverage'] : null;

		$isProfit = $pnl >= 0;
		$resultEmoji = $isProfit ? "\xE2\x9C\x85" : "\xE2\x9D\x8C"; // checkmark / cross

		$finishReasonEnum = PositionFinishReasonEnum::tryFrom($finishReason);
		$reasonLabel = match (true) {
			$finishReasonEnum?->isTakeProfit() => "\xF0\x9F\x8E\xAF Take Profit",
			$finishReasonEnum?->isStopLoss() => "\xF0\x9F\x9B\x91 Stop Loss",
			$finishReasonEnum?->isLiquidation() => "\xF0\x9F\x92\x80 Liquidation",
			default => $finishReason,
		};

		$pnlPercent = ($entryPrice > 0) ? ($pnl / ($entryPrice * (float)($a['volume'] ?? 1))) * 100 : 0;
		$pnlSign = $isProfit ? '+' : '';

		$message = "{$resultEmoji} <b>Close {$direction}</b>\n\n";
		$message .= "<b>Pair:</b> {$pair}\n";
		$message .= "<b>Exchange:</b> {$exchange} ({$timeframe})\n";
		if ($leverage !== null && $leverage > 1) {
			$message .= "<b>Leverage:</b> {$leverage}x\n";
		}
		$message .= "\n";
		$message .= "<b>Reason:</b> {$reasonLabel}\n";
		$message .= "<b>Entry:</b> " . $this->formatPrice($entryPrice) . "\n";
		$message .= "<b>Exit:</b> " . $this->formatPrice($currentPrice) . "\n";
		$message .= "\n";
		$message .= "<b>PnL:</b> {$pnlSign}" . number_format($pnl, 2) . " USDT ({$pnlSign}" . number_format($pnlPercent, 2) . "%)\n";
		$message .= "\xE2\x8F\xB1 <b>Duration:</b> " . $this->formatDuration($duration) . "\n";

		$this->logger->info("Position closed: {$direction} {$pair} PnL {$pnlSign}" . number_format($pnl, 2) . " USDT");
		$this->sendTelegramNotification($message);
		$task->remove();
	}

	/**
	 * Handle "Breakeven Lock" notification.
	 * Sends a formatted Telegram message when Breakeven Lock is executed.
	 */
	private function handleBreakevenLockNotification(QueueTask $task): void {
		$a = $task->getAttributes();

		$direction = strtoupper($a['direction'] ?? '?');
		$pair = $a['pair'] ?? '?';
		$exchange = $a['exchange'] ?? '?';
		$timeframe = $a['timeframe'] ?? '?';
		$entryPrice = (float)($a['entryPrice'] ?? 0);
		$currentPrice = (float)($a['currentPrice'] ?? 0);
		$closedVolume = (float)($a['closedVolume'] ?? 0);
		$lockedProfit = (float)($a['lockedProfit'] ?? 0);
		$slPrice = isset($a['slPrice']) ? (float)$a['slPrice'] : null;

		$message = "\xF0\x9F\x94\x92 <b>Breakeven Lock</b>\n\n";
		$message .= "<b>Pair:</b> {$pair} ({$direction})\n";
		$message .= "<b>Exchange:</b> {$exchange} ({$timeframe})\n";
		$message .= "\n";
		$message .= "<b>Entry:</b> " . $this->formatPrice($entryPrice) . "\n";
		$message .= "<b>Trigger Price:</b> " . $this->formatPrice($currentPrice) . "\n";
		$message .= "\n";
		$message .= "\xF0\x9F\x92\xB0 <b>Locked Profit:</b> +" . number_format($lockedProfit, 2) . " USDT\n";
		$message .= "\xF0\x9F\x93\x89 <b>Closed Volume:</b> " . $this->formatVolume($closedVolume) . "\n";
		if ($slPrice !== null) {
			$message .= "\xF0\x9F\x9B\x91 <b>New SL:</b> " . $this->formatPrice($slPrice) . " (breakeven)\n";
		}

		$this->logger->info("Breakeven Lock: {$direction} {$pair} locked +" . number_format($lockedProfit, 2) . " USDT");
		$this->sendTelegramNotification($message);
		$task->remove();
	}

	/**
	 * Format a price value for display. Uses up to 8 decimal places, trimming trailing zeros.
	 */
	private function formatPrice(float $price): string {
		if ($price >= 1.0) {
			return number_format($price, 4);
		}
		// For very small prices (memecoins), show more decimals.
		$formatted = rtrim(rtrim(number_format($price, 8), '0'), '.');
		return $formatted ?: '0';
	}

	/**
	 * Format a volume value for display.
	 */
	private function formatVolume(float $volume): string {
		if ($volume >= 1000) {
			return number_format($volume, 2);
		}
		return rtrim(rtrim(number_format($volume, 6), '0'), '.');
	}

	/**
	 * Format a duration in seconds to a human-readable string (e.g. "2d 5h 30m").
	 */
	private function formatDuration(int $seconds): string {
		if ($seconds < 60) {
			return "{$seconds}s";
		}

		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);

		$parts = [];
		if ($days > 0) {
			$parts[] = "{$days}d";
		}
		if ($hours > 0) {
			$parts[] = "{$hours}h";
		}
		if ($minutes > 0) {
			$parts[] = "{$minutes}m";
		}

		return implode(' ', $parts);
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


	private function handleIncomingMessages(): void {
		try {
			$updates = $this->telegram->getUpdates([
				'offset' => $this->lastUpdateId + 1,
				'timeout' => 60,
				'limit' => 10
			]);

			if (empty($updates)) {
				return;
			}

			foreach ($updates as $update) {
				try {
					if ($update->getCallbackQuery()) {
						$this->handleCallbackQuery($update);
					} elseif ($update->getMessage()) {
						$this->handleIncomingMessage($update);
					}
					$this->lastUpdateId = $update->getUpdateId();
				} catch (Exception $e) {
					$this->logger->error('Error processing update: '.$e->getMessage());
					// Continue with next update even if one fails.
				}
			}
		} catch (Exception $e) {
			$this->logger->error('Error getting updates: '.$e->getMessage());
		}
	}

	private function handleIncomingMessage($update): void {
		// Check if update has a message.
		if (!$update->getMessage()) {
			return;
		}

		$message = $update->getMessage();

		// If message is a collection, check its content
		if ($message instanceof \Illuminate\Support\Collection) {
			// Check if collection contains text (command)
			if ($message->has('text')) {
				$text = $message->get('text');

				// If it’s a command, process it
				if (str_starts_with($text, '/')) {
					$this->handleCommand($text);
					return;
				}
			}

			return;
		}

		// Filter only real Telegram messages
		if (!$message instanceof \Telegram\Bot\Objects\Message) {
			return;
		}

		$chat = $message->getChat();

		if (!$chat) {
			$this->logger->warning('Chat object is null');
			return;
		}

		// Get chat_id through method or property
		$chatId = null;
		if (method_exists($chat, 'getId')) {
			$chatId = $chat->getId();
		} elseif (property_exists($chat, 'id')) {
			$chatId = $chat->id;
		}

		if ($chatId != $this->telegramChatId) {
			return;
		}

		$text = $message->getText();
		if (!$text) {
			return;
		}

		// Parse command.
		if (str_starts_with($text, '/')) {
			$this->handleCommand($text);
		}
	}

	private function handleCallbackQuery($update): void {
		// Check if update has a callback query.
		if (!$update->getCallbackQuery()) {
			return;
		}

		$callbackQuery = $update->getCallbackQuery();
		$message = $callbackQuery->getMessage();

		// If message is a collection, check its content
		if ($message instanceof \Illuminate\Support\Collection) {
			// For callback queries we need message_id
			if ($message->has('message_id')) {
				$messageId = $message->get('message_id');

				// Get callback_data
				$callbackData = $callbackQuery->getData();

				// Parse callback data
				$parts = explode(':', $callbackData);
				$action = $parts[0];
				$args = array_slice($parts, 1);

				// Process callback
				$this->handleCallbackAction($action, $args, $messageId);
				return;
			}

			return;
		}

		// Filter only real Telegram messages
		if (!$message instanceof \Telegram\Bot\Objects\Message) {
			return;
		}

		$chat = $message->getChat();

		if (!$chat) {
			$this->logger->warning('Callback chat object is null');
			return;
		}

		// Get chat_id through method or property
		$chatId = null;
		if (method_exists($chat, 'getId')) {
			$chatId = $chat->getId();
		} elseif (property_exists($chat, 'id')) {
			$chatId = $chat->id;
		}

		if ($chatId != $this->telegramChatId) {
			return;
		}

		$callbackData = $callbackQuery->getData();
		$messageId = $message->getMessageId();

		// Parse callback data.
		$parts = explode(':', $callbackData);
		$action = $parts[0];
		$args = array_slice($parts, 1);

		$this->handleCallbackAction($action, $args, $messageId);
	}

	private function handleCommand(string $message): void {
		$parts = explode(' ', trim($message));
		$command = strtolower($parts[0]);
		$args = array_slice($parts, 1);

		switch ($command) {
			case '/start':
				$this->sendWelcomeMessage();
				break;
			case '/help':
				$this->sendHelpMessage();
				break;
			case '/chart':
				$this->handleChartCommand($args);
				break;
			case '/balance':
				$this->sendBalanceMenu();
				break;
			case '/exchanges':
				$this->sendExchangesList();
				break;
			case '/pairs':
				$this->handlePairsCommand($args);
				break;
			case '/menu':
				$this->sendMainMenu();
				break;
			default:
				$this->sendMessage("Unknown command. Use /help for reference.");
		}
	}

	private function sendMessage(string $message): void {
		$this->telegram->sendMessage([
			'chat_id' => $this->telegramChatId,
			'text' => $message
		]);
	}

	private function sendWelcomeMessage(): void {
		$message = "👋 Welcome to EZMoonblow!\n\n";
		$message .= "I'll help you analyze the cryptocurrency market.\n\n";
		$message .= "Use /menu for interactive menu or /help for command reference.";

		$this->sendMessage($message);
	}

	private function sendHelpMessage(): void {
		$message = "📚 Command Reference\n\n";
		$message .= "/menu — Interactive menu with buttons (recommended)\n\n";
		$message .= "/chart <exchange> <type> <pair> <timeframe>\n";
		$message .= "Build candlestick chart\n";
		$message .= "Timeframes: 1m, 3m, 5m, 15m, 30m, 1h, 2h, 4h, 6h, 12h, 1d, 1w, 1M\n";
		$message .= "Example: /chart Bybit spot BTC/USDT 15m\n\n";
		$message .= "/balance — Show balance chart\n\n";
		$message .= "💡 Tip: Use /menu for convenient selection via buttons!";

		$this->sendMessage($message);
	}

	private function sendExchangesList(): void {
		$message = "🏢 Available Exchanges:\n\n";

		foreach ($this->exchanges as $exchangeName => $exchange) {
			$message .= "• $exchangeName\n";
		}

		$message .= "\nUse: /pairs <exchange> <type> to view pairs";

		$this->sendMessage($message);
	}

	private function handlePairsCommand(array $args): void {
		if (count($args) < 2) {
			$this->sendMessage("Usage: /pairs <exchange> <type>\nExample: /pairs Bybit spot");
			return;
		}

		$exchangeName = $args[0];
		$marketTypeStr = $args[1];

		if (!isset($this->exchanges[$exchangeName])) {
			$this->sendMessage("❌ Exchange '$exchangeName' not found. Use /exchanges for list.");
			return;
		}

		$marketType = MarketTypeEnum::tryFrom($marketTypeStr);
		if (!$marketType) {
			$this->sendMessage("❌ Invalid market type '$marketTypeStr'. Use 'spot' or 'futures'.");
			return;
		}

		$pairs = $this->configuration->getAvailablePairTickers($exchangeName, $marketType);
		if (empty($pairs)) {
			$this->sendMessage("❌ No pairs found for $exchangeName ($marketTypeStr)");
			return;
		}

		$message = "📊 Available pairs on $exchangeName ($marketTypeStr):\n\n";
		foreach ($pairs as $pair) {
			$message .= "• $pair\n";
		}

		$message .= "\nUse: /chart $exchangeName {$marketType->value} <pair> <timeframe>";

		$this->sendMessage($message);
	}

	private function handleChartCommand(array $args): void {
		if (count($args) < 4) {
			$this->sendMessage("Usage: /chart <exchange> <type> <pair> <timeframe>\nExample: /chart Bybit spot BTC/USDT 15m");
			return;
		}

		[$exchangeName, $marketType, $pair, $timeframe] = $args;

		try {
			// Validate parameters.
			if (!$this->validateChartRequest($exchangeName, $marketType, $pair, $timeframe)) {
				$this->sendMessage("❌ Invalid parameters. Check the entered data.");
				return;
			}

			$this->sendMessage("🔄 Building chart...");

			// Build chart.
			$chartFilename = $this->buildChart($exchangeName, $marketType, $pair, $timeframe);

			if ($chartFilename) {
				$message = "📈 Chart $pair ($marketType, $timeframe) on $exchangeName";
				$this->sendTelegramNotification($message, $chartFilename);
			} else {
				$this->sendMessage("❌ Error building chart.");
			}

		} catch (Exception $e) {
			$this->logger->error('Chart command error: '.$e->getMessage());
			$this->sendMessage("❌ An error occurred while building the chart.");
		}
	}

	private function validateChartRequest(string $exchangeName, string $marketType, string $pair, string $timeframe): bool {
		// Validate exchange.
		if (!isset($this->exchanges[$exchangeName])) {
			return false;
		}

		// Validate market type.
		if (!MarketTypeEnum::tryFrom($marketType)) {
			return false;
		}

		// Validate timeframe.
		if (!TimeFrameEnum::tryFrom($timeframe)) {
			return false;
		}

		return true;
	}

	private function buildChart(string $exchangeName, string $marketType, string $pair, string $timeframe): ?string {
		try {
			$timeframeEnum = TimeFrameEnum::from($timeframe);
			$marketTypeEnum = MarketTypeEnum::from($marketType);

			$pairObj = new Pair($pair, $timeframeEnum, $exchangeName, $marketTypeEnum);
			$exchange = $this->configuration->connectExchange($this, $exchangeName);

			if (!$exchange) {
				return null;
			}

			$market = $exchange->createMarket($pairObj);
			return $market->drawChart();

		} catch (Exception $e) {
			$this->logger->error('Error building chart: '.$e->getMessage());
			return null;
		}
	}

	// Interactive menu methods
	private function sendMainMenu(): void {
		$message = "🎛️ Main Menu\n\nSelect an action:";

		$keyboard = [
			[['text' => '📊 Candlesticks Chart', 'callback_data' => 'exchanges']],
			[['text' => '📈 Charts with Indicators', 'callback_data' => 'charts_with_indicators']],
			[['text' => '💰 Balance Chart', 'callback_data' => 'balance']],
			[['text' => '❓ Help', 'callback_data' => 'help']]
		];

		$this->sendMessageWithKeyboard($message, $keyboard);
	}

	private function sendMessageWithKeyboard(string $message, array $keyboard): void {
		$this->telegram->sendMessage([
			'chat_id' => $this->telegramChatId,
			'text' => $message,
			'reply_markup' => json_encode([
				'inline_keyboard' => $keyboard
			])
		]);
	}

	private function editMessageWithKeyboard(string $message, array $keyboard, int $messageId): void {
		$this->telegram->editMessageText([
			'chat_id' => $this->telegramChatId,
			'message_id' => $messageId,
			'text' => $message,
			'reply_markup' => json_encode([
				'inline_keyboard' => $keyboard
			])
		]);
	}

	private function answerCallbackQuery(string $callbackQueryId, string $text): void {
		$this->telegram->answerCallbackQuery([
			'callback_query_id' => $callbackQueryId,
			'text' => $text
		]);
	}

	private function handleChartsWithIndicatorsCallback(array $args, int $messageId): void {
		$pairsWithIndicators = $this->configuration->getPairsWithIndicators();

		if (empty($pairsWithIndicators)) {
			$message = "❌ No pairs with indicators found in configuration.";
			$keyboard = [[['text' => '🔙 Back', 'callback_data' => 'back:main']]];
		} else {
			$message = "📈 Select chart with indicators:";

			$keyboard = [];
			foreach ($pairsWithIndicators as $pair) {
				$displayText = sprintf(
					"%s %s %s %s",
					$pair->getExchangeName(),
					$pair->getMarketType()->value,
					$pair->getTimeframe()->value,
					$pair->getTicker()
				);

				$callbackData = sprintf(
					"quick_chart:%s:%s:%s:%s",
					$pair->getExchangeName(),
					$pair->getMarketType()->value,
					$pair->getTicker(),
					$pair->getTimeframe()->value
				);

				$keyboard[] = [['text' => $displayText, 'callback_data' => $callbackData]];
			}
			$keyboard[] = [['text' => '🔙 Back', 'callback_data' => 'back:main']];
		}

		$this->editMessageWithKeyboard($message, $keyboard, $messageId);
	}

	private function handleQuickChartCallback(array $args, int $messageId): void {
		if (count($args) < 4)
			return;

		[$exchangeName, $marketType, $pair, $timeframe] = $args;

		// Update message to show progress.
		$this->editMessageWithKeyboard(
			"🔄 Building chart with indicators $pair ($marketType, $timeframe) on $exchangeName...",
			[],
			$messageId
		);

		try {
			// Validate parameters.
			if (!$this->validateChartRequest($exchangeName, $marketType, $pair, $timeframe)) {
				$this->editMessageWithKeyboard(
					"❌ Invalid parameters. Try again.",
					[[['text' => '🔙 Back', 'callback_data' => 'charts_with_indicators']]],
					$messageId
				);
				return;
			}

			// Build chart.
			$chartFilename = $this->buildChart($exchangeName, $marketType, $pair, $timeframe);

			if ($chartFilename) {
				$message = "📈 Chart with indicators $pair ($marketType, $timeframe) on $exchangeName";
				$this->sendTelegramNotification($message, $chartFilename);

				// Update the message to show success.
				$this->editMessageWithKeyboard(
					"✅ Chart with indicators successfully built and sent!",
					[[['text' => '🔄 New Chart', 'callback_data' => 'charts_with_indicators']]],
					$messageId
				);
			} else {
				$this->editMessageWithKeyboard(
					"❌ Error building chart.",
					[[['text' => '🔙 Back', 'callback_data' => 'charts_with_indicators']]],
					$messageId
				);
			}

		} catch (Exception $e) {
			$this->logger->error('Quick chart callback error: '.$e->getMessage());
			$this->editMessageWithKeyboard(
				"❌ An error occurred while building the chart.",
				[[['text' => '🔙 Back', 'callback_data' => 'charts_with_indicators']]],
				$messageId
			);
		}
	}

	private function handleExchangesCallback(array $args, int $messageId): void {
		$message = "🏢 Select Exchange:";

		$keyboard = [];
		foreach ($this->exchanges as $exchangeName => $exchange) {
			$keyboard[] = [['text' => $exchangeName, 'callback_data' => "market_type:$exchangeName"]];
		}
		$keyboard[] = [['text' => '🔙 Back', 'callback_data' => 'back:main']];

		$this->editMessageWithKeyboard($message, $keyboard, $messageId);
	}

	private function handleMarketTypeCallback(array $args, int $messageId): void {
		if (empty($args))
			return;

		$exchangeName = $args[0];
		$message = "📈 Select Market Type for $exchangeName:";

		$keyboard = [
			[['text' => '💱 Spot', 'callback_data' => "pairs:$exchangeName:spot"]],
			[['text' => '📈 Futures', 'callback_data' => "pairs:$exchangeName:futures"]],
			[['text' => '🔙 Back', 'callback_data' => 'back:exchanges']]
		];

		$this->editMessageWithKeyboard($message, $keyboard, $messageId);
	}

	private function handlePairsCallback(array $args, int $messageId): void {
		if (count($args) < 2)
			return;

		$exchangeName = $args[0];
		$marketTypeStr = $args[1];
		$marketType = MarketTypeEnum::tryFrom($marketTypeStr);
		if (!$marketType) {
			return;
		}

		$pairs = $this->configuration->getAvailablePairTickers($exchangeName, $marketType);
		if (empty($pairs)) {
			$message = "❌ No pairs found for $exchangeName ($marketTypeStr)";
			$keyboard = [[['text' => '🔙 Back', 'callback_data' => "market_type:$exchangeName"]]];
		} else {
			$message = "📊 Select Trading Pair ($exchangeName, $marketTypeStr):";

			$keyboard = [];
			foreach ($pairs as $pair) {
				$keyboard[] = [['text' => $pair, 'callback_data' => "timeframe:$exchangeName:$marketTypeStr:$pair"]];
			}
			$keyboard[] = [['text' => '🔙 Back', 'callback_data' => "market_type:$exchangeName"]];
		}

		$this->editMessageWithKeyboard($message, $keyboard, $messageId);
	}

	private function handleTimeframeCallback(array $args, int $messageId): void {
		if (count($args) < 3)
			return;

		$exchangeName = $args[0];
		$marketType = $args[1];
		$pair = $args[2];

		$message = "⏰ Select Timeframe for $pair:";

		$timeframes = [
			['text' => '1 minute', 'callback_data' => "build_chart:$exchangeName:$marketType:$pair:1m"],
			['text' => '5 minutes', 'callback_data' => "build_chart:$exchangeName:$marketType:$pair:5m"],
			['text' => '15 minutes', 'callback_data' => "build_chart:$exchangeName:$marketType:$pair:15m"],
			['text' => '30 minutes', 'callback_data' => "build_chart:$exchangeName:$marketType:$pair:30m"],
			['text' => '1 hour', 'callback_data' => "build_chart:$exchangeName:$marketType:$pair:1h"],
			['text' => '4 hours', 'callback_data' => "build_chart:$exchangeName:$marketType:$pair:4h"],
			['text' => '1 day', 'callback_data' => "build_chart:$exchangeName:$marketType:$pair:1d"]
		];

		$keyboard = [];
		foreach (array_chunk($timeframes, 2) as $row) {
			$keyboard[] = $row;
		}
		$keyboard[] = [['text' => '🔙 Back', 'callback_data' => "pairs:$exchangeName:$marketType"]];

		$this->editMessageWithKeyboard($message, $keyboard, $messageId);
	}

	private function handleBuildChartCallback(array $args, int $messageId): void {
		if (count($args) < 4)
			return;

		[$exchangeName, $marketType, $pair, $timeframe] = $args;

		// Update message to show progress.
		$this->editMessageWithKeyboard(
			"🔄 Building chart $pair ($marketType, $timeframe) on $exchangeName...",
			[],
			$messageId
		);

		try {
			// Validate parameters.
			if (!$this->validateChartRequest($exchangeName, $marketType, $pair, $timeframe)) {
				$this->editMessageWithKeyboard(
					"❌ Invalid parameters. Try again.",
					[[['text' => '🔙 Back', 'callback_data' => "timeframe:$exchangeName:$marketType:$pair"]]],
					$messageId
				);
				return;
			}

			// Build chart.
			$chartFilename = $this->buildChart($exchangeName, $marketType, $pair, $timeframe);

			if ($chartFilename) {
				$message = "📈 Chart $pair ($marketType, $timeframe) on $exchangeName";
				$this->sendTelegramNotification($message, $chartFilename);

				// Update the message to show success.
				$this->editMessageWithKeyboard(
					"✅ Chart successfully built and sent!",
					[[['text' => '🔄 New Chart', 'callback_data' => 'exchanges']]],
					$messageId
				);
			} else {
				$this->editMessageWithKeyboard(
					"❌ Error building chart.",
					[[['text' => '🔙 Back', 'callback_data' => "timeframe:$exchangeName:$marketType:$pair"]]],
					$messageId
				);
			}

		} catch (Exception $e) {
			$this->logger->error('Chart callback error: '.$e->getMessage());
			$this->editMessageWithKeyboard(
				"❌ An error occurred while building the chart.",
				[[['text' => '🔙 Back', 'callback_data' => "timeframe:$exchangeName:$marketType:$pair"]]],
				$messageId
			);
		}
	}

	private function handleCallbackAction(string $action, array $args, int $messageId): void {
		switch ($action) {
			case 'exchanges':
				$this->handleExchangesCallback($args, $messageId);
				break;
			case 'charts_with_indicators':
				$this->handleChartsWithIndicatorsCallback($args, $messageId);
				break;
			case 'quick_chart':
				$this->handleQuickChartCallback($args, $messageId);
				break;
			case 'balance':
				$this->handleBalanceCallback($args, $messageId);
				break;
			case 'market_type':
				$this->handleMarketTypeCallback($args, $messageId);
				break;
			case 'pairs':
				$this->handlePairsCallback($args, $messageId);
				break;
			case 'timeframe':
				$this->handleTimeframeCallback($args, $messageId);
				break;
			case 'build_chart':
				$this->handleBuildChartCallback($args, $messageId);
				break;
			case 'back':
				$this->handleBackCallback($args, $messageId);
				break;
			default:
				$this->logger->warning('Unknown callback action: '.$action);
		}
	}

	private function sendBalanceMenu(): void {
		$message = "💰 Balance Chart\n\nSelect period:";

		$keyboard = [
			[['text' => '📅 Day', 'callback_data' => 'balance:day']],
			[['text' => '📅 Month', 'callback_data' => 'balance:month']],
			[['text' => '📅 Year', 'callback_data' => 'balance:year']],
			[['text' => '🔙 Back', 'callback_data' => 'back:main']]
		];

		$this->sendMessageWithKeyboard($message, $keyboard);
	}

	private function handleBalanceCallback(array $args, int $messageId): void {
		if (empty($args)) {
			$this->sendBalanceMenu();
			return;
		}

		$period = $args[0];
		$validPeriods = ['day', 'month', 'year'];

		if (!in_array($period, $validPeriods)) {
			$this->editMessageWithKeyboard(
				"❌ Invalid period selected.",
				[[['text' => '🔙 Back', 'callback_data' => 'balance']]],
				$messageId
			);
			return;
		}

		$filename = "charts/balance_{$period}.png";

		if (!file_exists($filename)) {
			$this->editMessageWithKeyboard(
				"❌ Balance chart for $period period not found.",
				[[['text' => '🔙 Back', 'callback_data' => 'balance']]],
				$messageId
			);
			return;
		}

		// Send the balance chart
		$totalBalance = $this->database->getTotalBalance()->format();
		$message = "💰 Balance Chart ($period)\nTotal balance now: $totalBalance";
		$this->sendTelegramNotification($message, $filename);

		// Update the message to show success
		$this->editMessageWithKeyboard(
			"✅ Balance chart sent!",
			[[['text' => '🔄 New Balance Chart', 'callback_data' => 'balance']]],
			$messageId
		);
	}

	private function handleBackCallback(array $args, int $messageId): void {
		if (empty($args))
			return;

		$destination = $args[0];

		switch ($destination) {
			case 'main':
				$this->sendMainMenu();
				break;
			case 'exchanges':
				$this->handleExchangesCallback([], $messageId);
				break;
			default:
				$this->sendMainMenu();
		}
	}
}
