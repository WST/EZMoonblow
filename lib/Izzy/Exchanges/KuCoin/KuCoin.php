<?php

namespace Izzy\Exchanges\KuCoin;

use Exception;
use Izzy\Enums\MarginModeEnum;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\OrderStatusEnum;
use Izzy\Enums\OrderTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionModeEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\AbstractExchangeDriver;
use Izzy\Financial\Candle;
use Izzy\Financial\Money;
use Izzy\Financial\Order;
use Izzy\Financial\StoredPosition;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPositionOnExchange;
use Throwable;

/**
 * Driver for KuCoin exchange.
 *
 * Uses a lightweight KuCoinApiClient (Guzzle 7) with HMAC-SHA256 + Base64
 * authentication. Supports both spot and USDT-margined futures.
 */
class KuCoin extends AbstractExchangeDriver
{
	protected string $exchangeName = 'KuCoin';

	private KuCoinApiClient $api;

	/** @var array<string, array> Cached contract/symbol info per ticker. */
	private array $contractInfoCache = [];

	/**
	 * @inheritDoc
	 */
	public function connect(): bool {
		try {
			$key = $this->config->getKey();
			$secret = $this->config->getSecret();
			$passphrase = $this->config->getPassword();
			$this->api = new KuCoinApiClient($key, $secret, $passphrase);
			return true;
		} catch (Exception $e) {
			$this->logger->error("Failed to connect to KuCoin: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function disconnect(): void {
		exit(0);
	}

	/**
	 * Check if an exception represents a permanent API key or access error.
	 *
	 * @param Exception $e Exception to check.
	 * @return bool True if the exception is a permanent credential/access error.
	 */
	private function isApiKeyError(Exception $e): bool {
		$message = $e->getMessage();

		if (str_contains($message, '400100') || str_contains($message, 'Invalid API-KEY')) {
			return true;
		}

		if (str_contains($message, '400200') || str_contains($message, 'Forbidden')) {
			return true;
		}

		if (str_contains($message, '401') || str_contains($message, 'Unauthorized')) {
			return true;
		}

		return false;
	}

	// ───────────────────────── Balance ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function updateBalance(): void {
		try {
			$totalBalance = 0.0;

			try {
				$futuresResponse = $this->api->futuresGet('/api/v1/account-overview', [
					KuCoinParam::Currency => 'USDT',
				]);
				$totalBalance += (float) ($futuresResponse[KuCoinParam::AccountEquity] ?? 0);
			} catch (Exception) {
				// Futures account may not exist.
			}

			try {
				$spotAccounts = $this->api->get('/api/v1/accounts', [
					KuCoinParam::Currency => 'USDT',
					KuCoinParam::Type => 'trade',
				]);
				foreach ($spotAccounts as $account) {
					$totalBalance += (float) ($account[KuCoinParam::Balance] ?? 0);
				}
			} catch (Exception) {
				// Spot account may not exist.
			}

			if ($totalBalance <= 0) {
				$this->logger->error("Failed to get balance: no valid balance from KuCoin");
				return;
			}

			$this->saveBalance(Money::from($totalBalance));
		} catch (Exception $e) {
			$this->logger->error("Failed to update balance on KuCoin: " . $e->getMessage());

			if ($this->isApiKeyError($e)) {
				$this->logger->fatal("Invalid API credentials for KuCoin. Terminating process to prevent API abuse.", 0);
			}
		}
	}

	// ───────────────────────── Timeframe conversion ─────────────────────────

	/**
	 * Convert internal timeframe to KuCoin spot candle type string.
	 */
	private function timeframeToSpotInterval(TimeFrameEnum $timeframe): string {
		return match ($timeframe) {
			TimeFrameEnum::TF_1MINUTE => '1min',
			TimeFrameEnum::TF_3MINUTES => '3min',
			TimeFrameEnum::TF_5MINUTES => '5min',
			TimeFrameEnum::TF_15MINUTES => '15min',
			TimeFrameEnum::TF_30MINUTES => '30min',
			TimeFrameEnum::TF_1HOUR => '1hour',
			TimeFrameEnum::TF_2HOURS => '2hour',
			TimeFrameEnum::TF_4HOURS => '4hour',
			TimeFrameEnum::TF_6HOURS => '6hour',
			TimeFrameEnum::TF_12HOURS => '12hour',
			TimeFrameEnum::TF_1DAY => '1day',
			TimeFrameEnum::TF_1WEEK => '1week',
			default => '1hour',
		};
	}

	/**
	 * Convert internal timeframe to KuCoin futures granularity (minutes).
	 */
	private function timeframeToFuturesGranularity(TimeFrameEnum $timeframe): int {
		return match ($timeframe) {
			TimeFrameEnum::TF_1MINUTE => 1,
			TimeFrameEnum::TF_3MINUTES => 5,
			TimeFrameEnum::TF_5MINUTES => 5,
			TimeFrameEnum::TF_15MINUTES => 15,
			TimeFrameEnum::TF_30MINUTES => 30,
			TimeFrameEnum::TF_1HOUR => 60,
			TimeFrameEnum::TF_2HOURS => 120,
			TimeFrameEnum::TF_4HOURS => 240,
			TimeFrameEnum::TF_6HOURS => 480,
			TimeFrameEnum::TF_12HOURS => 720,
			TimeFrameEnum::TF_1DAY => 1440,
			TimeFrameEnum::TF_1WEEK => 10080,
			default => 60,
		};
	}

	// ───────────────────────── Market data ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function getCandles(
		IPair $pair,
		int $limit = 100,
		?int $startTime = null,
		?int $endTime = null
	): array {
		$ticker = $pair->getExchangeTicker($this);
		$timeframe = $pair->getTimeframe();

		try {
			if ($pair->isFutures()) {
				$granularity = $this->timeframeToFuturesGranularity($timeframe);
				$query = [
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Granularity => (string) $granularity,
				];

				if ($startTime !== null) {
					$query[KuCoinParam::From] = (string) $startTime;
				} elseif ($endTime !== null) {
					$query[KuCoinParam::From] = (string) ($endTime - $limit * $timeframe->toMilliseconds());
				} else {
					$query[KuCoinParam::From] = (string) ((time() - $limit * $timeframe->toSeconds()) * 1000);
				}

				if ($endTime !== null) {
					$query[KuCoinParam::To] = (string) $endTime;
				} else {
					$query[KuCoinParam::To] = (string) (time() * 1000);
				}

				$response = $this->api->futuresPublicGet('/api/v1/kline/query', $query);

				if (empty($response)) {
					return [];
				}

				$candles = array_map(
					fn(array $item) => new Candle(
						timestamp: $this->normalizeTimestamp((int) $item[KuCoinParam::FuturesKlineTime]),
						open: (float) $item[KuCoinParam::FuturesKlineOpen],
						high: (float) $item[KuCoinParam::FuturesKlineHigh],
						low: (float) $item[KuCoinParam::FuturesKlineLow],
						close: (float) $item[KuCoinParam::FuturesKlineClose],
						volume: (float) $item[KuCoinParam::FuturesKlineVolume],
					),
					$response
				);
			} else {
				$interval = $this->timeframeToSpotInterval($timeframe);
				$query = [
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Type => $interval,
				];

				if ($startTime !== null) {
					$query[KuCoinParam::StartAt] = (string) intdiv($startTime, 1000);
				}
				if ($endTime !== null) {
					$query[KuCoinParam::EndAt] = (string) intdiv($endTime, 1000);
				}

				$response = $this->api->publicGet('/api/v1/market/candles', $query);

				if (empty($response)) {
					return [];
				}

				$candles = array_map(
					fn(array $item) => new Candle(
						timestamp: (int) $item[KuCoinParam::SpotCandleTime],
						open: (float) $item[KuCoinParam::SpotCandleOpen],
						high: (float) $item[KuCoinParam::SpotCandleHigh],
						low: (float) $item[KuCoinParam::SpotCandleLow],
						close: (float) $item[KuCoinParam::SpotCandleClose],
						volume: (float) $item[KuCoinParam::SpotCandleVolume],
					),
					$response
				);
			}

			usort($candles, fn(Candle $a, Candle $b) => $a->getOpenTime() - $b->getOpenTime());
			return $candles;
		} catch (Exception $e) {
			$this->logger->error("Failed to get candles for $ticker on KuCoin: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(IMarket $market): ?Money {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$response = $this->api->futuresPublicGet('/api/v1/ticker', [
					KuCoinParam::Symbol => $ticker,
				]);
				if (!empty($response[KuCoinParam::Price])) {
					return Money::from($response[KuCoinParam::Price]);
				}
			} else {
				$response = $this->api->publicGet('/api/v1/market/orderbook/level1', [
					KuCoinParam::Symbol => $ticker,
				]);
				if (!empty($response[KuCoinParam::Price])) {
					return Money::from($response[KuCoinParam::Price]);
				}
			}

			$this->logger->error("Failed to get current price for $ticker: empty response");
			return null;
		} catch (Exception $e) {
			$this->logger->error("Failed to get current price for $ticker: " . $e->getMessage());
			return null;
		}
	}

	// ───────────────────────── Ticker conversion ─────────────────────────

	/**
	 * @inheritDoc
	 *
	 * KuCoin spot: "BTC-USDT" (dash separator).
	 * KuCoin futures: "BTCUSDTM" (concatenated + M suffix).
	 */
	public static function pairToTicker(IPair $pair): string {
		if ($pair->isFutures()) {
			return $pair->getBaseCurrency() . $pair->getQuoteCurrency() . 'M';
		}
		return $pair->getBaseCurrency() . '-' . $pair->getQuoteCurrency();
	}

	/**
	 * @inheritDoc
	 */
	public static function tickerToPair(string $exchangeTicker, MarketTypeEnum $marketType): string {
		if ($marketType->isFutures() && str_ends_with($exchangeTicker, 'M')) {
			$withoutSuffix = substr($exchangeTicker, 0, -1);
			$usdtPos = strpos($withoutSuffix, 'USDT');
			if ($usdtPos !== false) {
				return substr($withoutSuffix, 0, $usdtPos) . '/USDT';
			}
		}

		if (str_contains($exchangeTicker, '-')) {
			return str_replace('-', '/', $exchangeTicker);
		}

		return $exchangeTicker;
	}

	// ───────────────────────── Trading ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function openPosition(
		IMarket $market,
		PositionDirectionEnum $direction,
		Money $amount,
		?Money $price = null,
		?float $takeProfitPercent = null,
		?float $stopLossPercent = null
	): bool {
		$currentPrice = $this->getCurrentPrice($market);
		if ($currentPrice === null) {
			$this->logger->error("Cannot open position on KuCoin: failed to get current price for $market");
			return false;
		}

		if ($price !== null) {
			$orderIdOnExchange = $this->placeLimitOrder(
				market: $market,
				amount: $amount,
				price: $price,
				direction: $direction,
				takeProfitPercent: $takeProfitPercent,
			);
			if ($orderIdOnExchange) {
				$position = StoredPosition::create(
					market: $market,
					volume: $amount,
					direction: $direction,
					entryPrice: $currentPrice,
					currentPrice: $currentPrice,
					status: PositionStatusEnum::OPEN,
					exchangePositionId: $orderIdOnExchange,
				);
				if ($takeProfitPercent) {
					$takeProfitPrice = $price->modifyByPercentWithDirection($takeProfitPercent, $direction);
					$position->setExpectedProfitPercent($takeProfitPercent);
					$position->setTakeProfitPrice($takeProfitPrice);
				}
				$position->setAverageEntryPrice($currentPrice);
				$saved = $position->save();
				if (!$saved) {
					$this->logger->error("Position opened on KuCoin but failed to save to DB for $market");
				}
				return true;
			}
			return false;
		}

		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$entryVolume = $market->calculateQuantity($amount, $currentPrice);
				$contracts = $this->volumeToContracts($market, $entryVolume);
				$leverage = $pair->getLeverage() ?? 10;

				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => $direction->isLong() ? KuCoinParam::SideBuy : KuCoinParam::SideSell,
					KuCoinParam::Type => KuCoinParam::TypeMarket,
					KuCoinParam::Leverage => (string) $leverage,
					KuCoinParam::Size => $contracts,
				];

				$response = $this->api->futuresPost('/api/v1/orders', $body);
			} else {
				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => strtolower($direction->getBuySell()),
					KuCoinParam::Type => KuCoinParam::TypeMarket,
					KuCoinParam::Size => (string) $amount->getAmount(),
				];

				$response = $this->api->post('/api/v1/orders', $body);
			}

			$orderId = (string) ($response[KuCoinParam::OrderId] ?? '');
			if (empty($orderId)) {
				$this->logger->error("Failed to open position on KuCoin for $market: " . json_encode($response));
				return false;
			}

			$this->logger->warning("Successfully opened a position on KuCoin for $market");

			$entryVolume = $pair->isFutures()
				? $market->calculateQuantity($amount, $currentPrice)
				: $amount;

			$position = StoredPosition::create(
				market: $market,
				volume: $entryVolume,
				direction: $direction,
				entryPrice: $currentPrice,
				currentPrice: $currentPrice,
				status: PositionStatusEnum::OPEN,
				exchangePositionId: $orderId,
			);
			$position->setAverageEntryPrice($currentPrice);

			if ($takeProfitPercent) {
				$position->setExpectedProfitPercent($takeProfitPercent);
				$takeProfitPrice = $currentPrice->modifyByPercentWithDirection($takeProfitPercent, $direction);
				$position->setTakeProfitPrice($takeProfitPrice);
				$this->setTakeProfit($market, $takeProfitPrice, $direction);
			}

			if ($stopLossPercent && $pair->isFutures()) {
				$stopLossPrice = $currentPrice->modifyByPercentWithDirection(-$stopLossPercent, $direction);
				$position->setExpectedStopLossPercent($stopLossPercent);
				$position->setStopLossPrice($stopLossPrice);
				$this->setStopLoss($market, $stopLossPrice, $direction);
			}

			$saved = $position->save();
			if (!$saved) {
				$this->logger->error("Position opened on KuCoin but failed to save to DB for $market");
			}

			return true;
		} catch (Exception $e) {
			$this->logger->error("Failed to open position on KuCoin for $market: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$currentPrice = $this->getCurrentPrice($market);
				if ($currentPrice === null) {
					return false;
				}
				$entryVolume = $market->calculateQuantity($amount, $currentPrice);
				$contracts = $this->volumeToContracts($market, $entryVolume);
				$leverage = $pair->getLeverage() ?? 10;

				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => KuCoinParam::SideBuy,
					KuCoinParam::Type => KuCoinParam::TypeMarket,
					KuCoinParam::Leverage => (string) $leverage,
					KuCoinParam::Size => $contracts,
				];
				$response = $this->api->futuresPost('/api/v1/orders', $body);
			} else {
				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => KuCoinParam::SideBuy,
					KuCoinParam::Type => KuCoinParam::TypeMarket,
					KuCoinParam::Size => (string) $amount->getAmount(),
				];
				$response = $this->api->post('/api/v1/orders', $body);
			}

			if (!empty($response[KuCoinParam::OrderId])) {
				$this->logger->info("Successfully executed DCA buy on KuCoin for $ticker: $amount");
				return true;
			}

			$this->logger->error("Failed to execute DCA buy on KuCoin for $ticker: " . json_encode($response));
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to execute DCA buy on KuCoin for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function sellAdditional(IMarket $market, Money $amount): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$currentPrice = $this->getCurrentPrice($market);
				if ($currentPrice === null) {
					return false;
				}
				$entryVolume = $market->calculateQuantity($amount, $currentPrice);
				$contracts = $this->volumeToContracts($market, $entryVolume);
				$leverage = $pair->getLeverage() ?? 10;

				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => KuCoinParam::SideSell,
					KuCoinParam::Type => KuCoinParam::TypeMarket,
					KuCoinParam::Leverage => (string) $leverage,
					KuCoinParam::Size => $contracts,
				];
				$response = $this->api->futuresPost('/api/v1/orders', $body);
			} else {
				$currentPrice = $this->getCurrentPrice($market);
				if ($currentPrice === null) {
					return false;
				}
				$qty = $market->calculateQuantity($amount, $currentPrice);

				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => KuCoinParam::SideSell,
					KuCoinParam::Type => KuCoinParam::TypeMarket,
					KuCoinParam::Size => $qty->formatForOrder($this->getQtyStep($market)),
				];
				$response = $this->api->post('/api/v1/orders', $body);
			}

			if (!empty($response[KuCoinParam::OrderId])) {
				$this->logger->info("Successfully executed DCA sell on KuCoin for $ticker: $amount");
				return true;
			}

			$this->logger->error("Failed to execute DCA sell on KuCoin for $ticker: " . json_encode($response));
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to execute DCA sell on KuCoin for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function placeLimitOrder(
		IMarket $market,
		Money $amount,
		Money $price,
		PositionDirectionEnum $direction,
		?float $takeProfitPercent = null
	): string|false {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$contracts = $this->volumeToContracts($market, $amount);
				$leverage = $pair->getLeverage() ?? 10;

				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => $direction->isLong() ? KuCoinParam::SideBuy : KuCoinParam::SideSell,
					KuCoinParam::Type => KuCoinParam::TypeLimit,
					KuCoinParam::Leverage => (string) $leverage,
					KuCoinParam::Size => $contracts,
					KuCoinParam::Price => $price->formatForOrder($this->getTickSize($market)),
					KuCoinParam::TimeInForce => KuCoinParam::GTC,
				];

				$response = $this->api->futuresPost('/api/v1/orders', $body);
			} else {
				$body = [
					KuCoinParam::ClientOid => $this->generateClientOid(),
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Side => strtolower($direction->getBuySell()),
					KuCoinParam::Type => KuCoinParam::TypeLimit,
					KuCoinParam::Size => $amount->formatForOrder($this->getQtyStep($market)),
					KuCoinParam::Price => $price->formatForOrder($this->getTickSize($market)),
					KuCoinParam::TimeInForce => KuCoinParam::GTC,
				];

				$response = $this->api->post('/api/v1/orders', $body);
			}

			$orderId = (string) ($response[KuCoinParam::OrderId] ?? '');
			if (!empty($orderId)) {
				if ($takeProfitPercent && $pair->isFutures()) {
					$takeProfitPrice = $price->modifyByPercentWithDirection($takeProfitPercent, $direction);
					$this->setTakeProfit($market, $takeProfitPrice, $direction);
				}
				return $orderId;
			}

			$this->logger->error("Failed to place limit order on KuCoin for $market: " . json_encode($response));
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to place limit order on KuCoin for $market: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function removeLimitOrders(IMarket $market): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$this->api->futuresDelete('/api/v1/orders', [
					KuCoinParam::Symbol => $ticker,
				]);
			} else {
				$this->api->delete('/api/v1/orders', [
					KuCoinParam::Symbol => $ticker,
				]);
			}
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to remove limit orders on KuCoin for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function removeLimitOrdersByDirection(IMarket $market, PositionDirectionEnum $direction): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$response = $this->api->futuresGet('/api/v1/orders', [
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Status => 'active',
				]);

				$orders = $response[KuCoinParam::Items] ?? $response;
				$targetSide = $direction->isLong() ? KuCoinParam::SideBuy : KuCoinParam::SideSell;

				foreach ($orders as $order) {
					if (($order[KuCoinParam::Side] ?? '') !== $targetSide) {
						continue;
					}
					$this->api->futuresDelete('/api/v1/orders/' . $order[KuCoinParam::Id]);
				}
			} else {
				$response = $this->api->get('/api/v1/orders', [
					KuCoinParam::Symbol => $ticker,
					KuCoinParam::Status => 'active',
				]);

				$orders = $response[KuCoinParam::Items] ?? $response;
				$targetSide = strtolower($direction->getBuySell());

				foreach ($orders as $order) {
					if (($order[KuCoinParam::Side] ?? '') !== $targetSide) {
						continue;
					}
					$this->api->delete('/api/v1/orders/' . $order[KuCoinParam::Id]);
				}
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to remove limit orders by direction on KuCoin for $ticker: " . $e->getMessage());
			return false;
		}
	}

	// ───────────────────────── TP / SL via st-orders ─────────────────────────

	/**
	 * @inheritDoc
	 *
	 * KuCoin uses native st-orders (stop-profit/stop-loss) endpoint.
	 */
	public function setTakeProfit(IMarket $market, Money $expectedPrice, PositionDirectionEnum $direction): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			$closeSide = $direction->isLong() ? KuCoinParam::SideSell : KuCoinParam::SideBuy;
			$isUpTrigger = $direction->isLong();

			$this->cancelStOrdersByTrigger($ticker, $isUpTrigger);

			$body = [
				KuCoinParam::ClientOid => $this->generateClientOid(),
				KuCoinParam::Symbol => $ticker,
				KuCoinParam::Side => $closeSide,
				KuCoinParam::Type => KuCoinParam::TypeMarket,
				KuCoinParam::CloseOrder => true,
				KuCoinParam::StopPriceType => 'TP',
			];

			if ($direction->isLong()) {
				$body[KuCoinParam::TriggerStopUpPrice] = $expectedPrice->formatForOrder($this->getTickSize($market));
			} else {
				$body[KuCoinParam::TriggerStopDownPrice] = $expectedPrice->formatForOrder($this->getTickSize($market));
			}

			$this->api->futuresPost('/api/v1/st-orders', $body);
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to set TP for $ticker on KuCoin: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 *
	 * KuCoin uses native st-orders (stop-profit/stop-loss) endpoint.
	 */
	public function setStopLoss(IMarket $market, Money $expectedPrice, PositionDirectionEnum $direction): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			$closeSide = $direction->isLong() ? KuCoinParam::SideSell : KuCoinParam::SideBuy;
			$isUpTrigger = !$direction->isLong();

			$this->cancelStOrdersByTrigger($ticker, $isUpTrigger);

			$body = [
				KuCoinParam::ClientOid => $this->generateClientOid(),
				KuCoinParam::Symbol => $ticker,
				KuCoinParam::Side => $closeSide,
				KuCoinParam::Type => KuCoinParam::TypeMarket,
				KuCoinParam::CloseOrder => true,
				KuCoinParam::StopPriceType => 'TP',
			];

			if ($direction->isLong()) {
				$body[KuCoinParam::TriggerStopDownPrice] = $expectedPrice->formatForOrder($this->getTickSize($market));
			} else {
				$body[KuCoinParam::TriggerStopUpPrice] = $expectedPrice->formatForOrder($this->getTickSize($market));
			}

			$this->api->futuresPost('/api/v1/st-orders', $body);
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to set SL for $ticker on KuCoin: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function partialClose(IMarket $market, Money $volume, bool $isBreakevenLock = false, ?Money $closePrice = null, ?PositionDirectionEnum $direction = null): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($direction === null) {
				$position = $this->getCurrentFuturesPosition($market);
				if (!$position) {
					$this->logger->error("Cannot partial close on KuCoin: no position found for $ticker");
					return false;
				}
				$direction = $position->getDirection();
			}

			$contracts = $this->volumeToContracts($market, $volume);
			$closeSide = $direction->isLong() ? KuCoinParam::SideSell : KuCoinParam::SideBuy;
			$leverage = $pair->getLeverage() ?? 10;

			$body = [
				KuCoinParam::ClientOid => $this->generateClientOid(),
				KuCoinParam::Symbol => $ticker,
				KuCoinParam::Side => $closeSide,
				KuCoinParam::Type => KuCoinParam::TypeMarket,
				KuCoinParam::Leverage => (string) $leverage,
				KuCoinParam::Size => $contracts,
				KuCoinParam::ReduceOnly => true,
			];

			$response = $this->api->futuresPost('/api/v1/orders', $body);

			if (!empty($response[KuCoinParam::OrderId])) {
				$this->logger->info("Partial close on KuCoin for $ticker: closed {$volume->format()}");
				return true;
			}

			$this->logger->error("Failed to partial close on KuCoin for $ticker: " . json_encode($response));
			return false;
		} catch (Throwable $e) {
			$this->logger->error("Failed to partial close on KuCoin for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function placeLimitClose(
		IMarket $market,
		Money $volume,
		Money $price,
		PositionDirectionEnum $direction,
	): string|false {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			$contracts = $this->volumeToContracts($market, $volume);
			$closeSide = $direction->isLong() ? KuCoinParam::SideSell : KuCoinParam::SideBuy;
			$leverage = $pair->getLeverage() ?? 10;

			$body = [
				KuCoinParam::ClientOid => $this->generateClientOid(),
				KuCoinParam::Symbol => $ticker,
				KuCoinParam::Side => $closeSide,
				KuCoinParam::Type => KuCoinParam::TypeLimit,
				KuCoinParam::Leverage => (string) $leverage,
				KuCoinParam::Size => $contracts,
				KuCoinParam::Price => $price->formatForOrder($this->getTickSize($market)),
				KuCoinParam::TimeInForce => KuCoinParam::GTC,
				KuCoinParam::ReduceOnly => true,
			];

			$response = $this->api->futuresPost('/api/v1/orders', $body);

			$orderId = (string) ($response[KuCoinParam::OrderId] ?? '');
			if (!empty($orderId)) {
				$this->logger->info("Placed limit close on KuCoin for $ticker: {$volume->format()} @ {$price->format()}");
				return $orderId;
			}

			$this->logger->error("Failed to place limit close on KuCoin for $ticker: " . json_encode($response));
			return false;
		} catch (Throwable $e) {
			$this->logger->error("Failed to place limit close on KuCoin for $ticker: " . $e->getMessage());
			return false;
		}
	}

	// ───────────────────────── Order info ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function getOrderById(IMarket $market, string $orderIdOnExchange): Order|false {
		$pair = $market->getPair();

		try {
			if ($pair->isFutures()) {
				$response = $this->api->futuresGet("/api/v1/orders/$orderIdOnExchange");
			} else {
				$response = $this->api->get("/api/v1/orders/$orderIdOnExchange");
			}

			if (empty($response) || !isset($response[KuCoinParam::Id])) {
				return false;
			}

			return $this->buildOrderFromResponse($response, $pair->isFutures());
		} catch (Throwable $e) {
			$this->logger->error("Failed to get order $orderIdOnExchange on KuCoin: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function hasActiveOrder(IMarket $market, string $orderIdOnExchange): bool {
		$order = $this->getOrderById($market, $orderIdOnExchange);
		return ($order !== false) && $order->isActive();
	}

	// ───────────────────────── Position & margin info ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function getMarginMode(IMarket $market): ?MarginModeEnum {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			$response = $this->api->futuresGet('/api/v1/position', [
				KuCoinParam::Symbol => $ticker,
			]);

			$crossMode = $response[KuCoinParam::CrossMode] ?? true;
			return $crossMode ? MarginModeEnum::CROSS : MarginModeEnum::ISOLATED;
		} catch (Throwable $e) {
			$this->logger->error("Failed to get margin mode for $ticker on KuCoin: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getLeverage(IMarket $market): ?float {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			$response = $this->api->futuresGet('/api/v1/position', [
				KuCoinParam::Symbol => $ticker,
			]);
			$leverage = (float) ($response[KuCoinParam::RealLeverage] ?? 0);
			return $leverage > 0 ? $leverage : null;
		} catch (Throwable $e) {
			$this->logger->error("Failed to get leverage for $ticker on KuCoin: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * @inheritDoc
	 *
	 * KuCoin futures defaults to one-way mode.
	 */
	public function getPositionMode(IMarket $market): ?PositionModeEnum {
		return PositionModeEnum::ONE_WAY;
	}

	/**
	 * @inheritDoc
	 */
	public function getQtyStep(IMarket $market): string {
		$info = $this->getContractInfo($market);
		if ($info === null) {
			return '1';
		}

		if ($market->getPair()->isFutures()) {
			$multiplier = $info[KuCoinParam::Multiplier] ?? 1;
			return (string) abs((float) $multiplier);
		}

		return $info['baseIncrement'] ?? '0.00000001';
	}

	/**
	 * @inheritDoc
	 */
	public function getTickSize(IMarket $market): string {
		$info = $this->getContractInfo($market);
		if ($info === null) {
			return '0.0001';
		}

		if ($market->getPair()->isFutures()) {
			$tickSize = $info[KuCoinParam::TickSize] ?? 0.0001;
			return (string) (float) $tickSize;
		}

		return $info['priceIncrement'] ?? '0.0001';
	}

	/**
	 * @inheritDoc
	 */
	public function getTakerFee(MarketTypeEnum $marketType): float {
		return match ($marketType) {
			MarketTypeEnum::FUTURES => 0.0006,
			MarketTypeEnum::SPOT => 0.001,
		};
	}

	/**
	 * @inheritDoc
	 */
	public function getMakerFee(MarketTypeEnum $marketType): float {
		return match ($marketType) {
			MarketTypeEnum::FUTURES => 0.0002,
			MarketTypeEnum::SPOT => 0.001,
		};
	}

	// ───────────────────────── Spot balance ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function getSpotBalanceByCurrency(string $coin): Money {
		try {
			$response = $this->api->get('/api/v1/accounts', [
				KuCoinParam::Currency => $coin,
				KuCoinParam::Type => 'trade',
			]);

			if (!empty($response)) {
				foreach ($response as $account) {
					if (($account[KuCoinParam::Currency] ?? '') === $coin) {
						return Money::from($account[KuCoinParam::Available] ?? '0', $coin);
					}
				}
			}

			return Money::from(0, $coin);
		} catch (Exception $e) {
			$this->logger->error("Failed to get spot balance for $coin on KuCoin: " . $e->getMessage());
			return Money::from(0, $coin);
		}
	}

	// ───────────────────────── Futures positions ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function getCurrentFuturesPosition(IMarket $market): IPositionOnExchange|false {
		$pair = $market->getPair();
		if (!$pair->isFutures()) {
			$this->logger->error("Trying to get futures position on spot market: $market");
			return false;
		}

		$ticker = $pair->getExchangeTicker($this);

		try {
			$response = $this->api->futuresGet('/api/v1/position', [
				KuCoinParam::Symbol => $ticker,
			]);

			$currentQty = (int) ($response[KuCoinParam::CurrentQty] ?? 0);
			if ($currentQty === 0) {
				return false;
			}

			$response = $this->enrichPositionWithContractInfo($response, $ticker);
			return PositionOnKuCoin::create($market, $response);
		} catch (Exception $e) {
			$this->logger->error("Failed to get futures position for $ticker on KuCoin: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentFuturesPositionByDirection(IMarket $market, PositionDirectionEnum $direction): IPositionOnExchange|false {
		$position = $this->getCurrentFuturesPosition($market);
		if ($position === false) {
			return false;
		}

		return $position->getDirection() === $direction ? $position : false;
	}

	// ───────────────────────── Top pairs ─────────────────────────

	/**
	 * @inheritDoc
	 */
	public function getTopPairsByVolume(int $limit, string $category = 'linear'): array {
		try {
			if ($category === MarketTypeEnum::SPOT->value) {
				$response = $this->api->publicGet('/api/v1/market/allTickers');
				$tickers = $response['ticker'] ?? [];
				$marketType = MarketTypeEnum::SPOT;

				usort($tickers, fn(array $a, array $b) =>
					(float) ($b['volValue'] ?? 0) <=> (float) ($a['volValue'] ?? 0)
				);

				$pairs = [];
				foreach ($tickers as $ticker) {
					$symbol = $ticker[KuCoinParam::Symbol] ?? '';
					if (empty($symbol) || !str_ends_with($symbol, '-USDT')) {
						continue;
					}
					$pair = static::tickerToPair($symbol, $marketType);
					if ($pair !== '' && !in_array($pair, $pairs, true)) {
						$pairs[] = $pair;
					}
					if (count($pairs) >= $limit) {
						break;
					}
				}

				return $pairs;
			}

			$response = $this->api->futuresPublicGet('/api/v1/contracts/active');
			$marketType = MarketTypeEnum::FUTURES;

			usort($response, fn(array $a, array $b) =>
				(float) ($b[KuCoinParam::Turnover24h] ?? 0) <=> (float) ($a[KuCoinParam::Turnover24h] ?? 0)
			);

			$pairs = [];
			foreach ($response as $contract) {
				$symbol = $contract[KuCoinParam::Symbol] ?? '';
				if (empty($symbol) || !str_contains($symbol, 'USDT')) {
					continue;
				}
				$pair = static::tickerToPair($symbol, $marketType);
				if ($pair !== '' && !in_array($pair, $pairs, true)) {
					$pairs[] = $pair;
				}
				if (count($pairs) >= $limit) {
					break;
				}
			}

			return $pairs;
		} catch (Throwable $e) {
			$this->logger->error("Failed to fetch tickers from KuCoin: " . $e->getMessage());
			return [];
		}
	}

	// ───────────────────────── Private helpers ─────────────────────────

	/**
	 * Get contract/symbol specification (cached).
	 *
	 * @return array|null Contract info or null if unavailable.
	 */
	private function getContractInfo(IMarket $market): ?array {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		if (isset($this->contractInfoCache[$ticker])) {
			return $this->contractInfoCache[$ticker];
		}

		try {
			if ($pair->isFutures()) {
				$response = $this->api->futuresPublicGet("/api/v1/contracts/$ticker");
			} else {
				$response = $this->api->publicGet("/api/v2/symbols/$ticker");
			}

			$this->contractInfoCache[$ticker] = $response;
			return $response;
		} catch (Throwable $e) {
			$this->logger->error("Failed to get contract info for $ticker on KuCoin: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Convert base currency volume to KuCoin contract count.
	 *
	 * KuCoin futures use integer contract sizes. Each contract represents
	 * multiplier units of the base currency.
	 */
	private function volumeToContracts(IMarket $market, Money $volume): int {
		$info = $this->getContractInfo($market);
		$multiplier = abs((float) ($info[KuCoinParam::Multiplier] ?? 1));
		$contracts = (int) floor($volume->getAmount() / $multiplier);
		return max($contracts, 1);
	}

	/**
	 * Generate a unique client order ID.
	 */
	private function generateClientOid(): string {
		return uniqid('izzy_', true);
	}

	/**
	 * Normalize timestamp to seconds (handles both seconds and milliseconds).
	 */
	private function normalizeTimestamp(int $timestamp): int {
		return $timestamp > 1_000_000_000_000 ? intdiv($timestamp, 1000) : $timestamp;
	}

	/**
	 * Cancel existing st-orders matching a specific trigger direction.
	 *
	 * @param string $ticker Exchange ticker.
	 * @param bool $cancelUpTrigger True to cancel orders with triggerStopUpPrice,
	 *                              false to cancel orders with triggerStopDownPrice.
	 */
	private function cancelStOrdersByTrigger(string $ticker, bool $cancelUpTrigger): void {
		try {
			$response = $this->api->futuresGet('/api/v1/st-orders', [
				KuCoinParam::Symbol => $ticker,
				KuCoinParam::Status => 'active',
			]);

			$orders = $response[KuCoinParam::Items] ?? $response;

			foreach ($orders as $order) {
				$hasUpTrigger = !empty($order[KuCoinParam::TriggerStopUpPrice]);
				$hasDownTrigger = !empty($order[KuCoinParam::TriggerStopDownPrice]);

				if ($cancelUpTrigger && $hasUpTrigger) {
					$this->api->futuresDelete('/api/v1/st-orders/' . $order[KuCoinParam::Id]);
				} elseif (!$cancelUpTrigger && $hasDownTrigger) {
					$this->api->futuresDelete('/api/v1/st-orders/' . $order[KuCoinParam::Id]);
				}
			}
		} catch (Throwable) {
			// Silently ignore — old st-orders may already be cancelled.
		}
	}

	/**
	 * Enrich position info with multiplier from contract specs.
	 */
	private function enrichPositionWithContractInfo(array $posInfo, string $ticker): array {
		$contractInfo = $this->contractInfoCache[$ticker] ?? null;
		if ($contractInfo === null) {
			try {
				$contractInfo = $this->api->futuresPublicGet("/api/v1/contracts/$ticker");
				$this->contractInfoCache[$ticker] = $contractInfo;
			} catch (Throwable) {
				return $posInfo;
			}
		}
		$posInfo[KuCoinParam::Multiplier] = $contractInfo[KuCoinParam::Multiplier] ?? 1;
		$posInfo[KuCoinParam::Symbol] = $ticker;
		return $posInfo;
	}

	/**
	 * Build an Order object from KuCoin API order response.
	 */
	private function buildOrderFromResponse(array $orderInfo, bool $isFutures): Order {
		$order = new Order();
		$order->setIdOnExchange((string) ($orderInfo[KuCoinParam::Id] ?? ''));

		if ($isFutures) {
			$filledSize = (int) ($orderInfo[KuCoinParam::FilledSize] ?? $orderInfo[KuCoinParam::DealSize] ?? 0);
			$order->setVolume(Money::from($filledSize));
		} else {
			$order->setVolume(Money::from($orderInfo[KuCoinParam::DealSize] ?? '0'));
		}

		$isActive = (bool) ($orderInfo[KuCoinParam::IsActive] ?? false);
		$cancelExist = (bool) ($orderInfo[KuCoinParam::CancelExist] ?? false);
		$order->setStatus($this->mapKuCoinOrderStatus($isActive, $cancelExist, $orderInfo));

		$price = $orderInfo[KuCoinParam::Price] ?? '0';
		$orderType = ($price === '0' || $price === '' || ($orderInfo[KuCoinParam::Type] ?? '') === KuCoinParam::TypeMarket)
			? OrderTypeEnum::MARKET
			: OrderTypeEnum::LIMIT;
		$order->setOrderType($orderType);

		return $order;
	}

	/**
	 * Map KuCoin order status to internal OrderStatusEnum.
	 */
	private function mapKuCoinOrderStatus(bool $isActive, bool $cancelExist, array $orderInfo): OrderStatusEnum {
		if ($isActive) {
			$dealSize = (float) ($orderInfo[KuCoinParam::DealSize] ?? $orderInfo[KuCoinParam::FilledSize] ?? 0);
			return $dealSize > 0 ? OrderStatusEnum::PartiallyFilled : OrderStatusEnum::NewOrder;
		}

		if ($cancelExist) {
			return OrderStatusEnum::Cancelled;
		}

		return OrderStatusEnum::Filled;
	}
}
