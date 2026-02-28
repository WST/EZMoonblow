<?php

namespace Izzy\Exchanges\Gate;

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
 * Driver for Gate.io exchange.
 *
 * Uses a lightweight GateApiClient (Guzzle 7) instead of the official SDK
 * which requires Guzzle 6 and has PHP 8.4 deprecation issues.
 */
class Gate extends AbstractExchangeDriver
{
	protected string $exchangeName = 'Gate';

	private GateApiClient $api;

	private const string SETTLE = 'usdt';

	/** @var array<string, array> Cached contract info per symbol. */
	private array $contractInfoCache = [];

	/** @var array<string, MarginModeEnum> Cached margin modes per symbol. */
	private array $marginModeCache = [];

	/** @var bool|null Cached dual mode state (null = not fetched yet). */
	private ?bool $dualModeCache = null;

	/**
	 * @inheritDoc
	 */
	public function connect(): bool {
		try {
			$key = $this->config->getKey();
			$secret = $this->config->getSecret();
			$this->api = new GateApiClient($key, $secret);
			return true;
		} catch (Exception $e) {
			$this->logger->error("Failed to connect to Gate: " . $e->getMessage());
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
	 * Check if an exception represents a permanent API key or access error
	 * that will not resolve itself (deleted key, locked account, wrong IP, etc.).
	 *
	 * @param Exception $e Exception to check.
	 * @return bool True if the exception is a permanent credential/access error.
	 */
	private function isApiKeyError(Exception $e): bool {
		$lowerMessage = strtolower($e->getMessage());

		if (str_contains($lowerMessage, 'account_locked') ||
			str_contains($lowerMessage, 'invalid_key') ||
			str_contains($lowerMessage, 'api_key is deleted')) {
			return true;
		}

		if (str_contains($lowerMessage, '401 unauthorized')) {
			return true;
		}

		if (str_contains($lowerMessage, 'authentication') ||
			str_contains($lowerMessage, 'forbidden')) {
			return true;
		}

		return false;
	}

	/**
	 * Check if an exception is a "position not found" response from Gate.
	 * This is normal when no position is open — not a real error.
	 */
	private function isPositionNotFound(Throwable $e): bool {
		return str_contains($e->getMessage(), 'POSITION_NOT_FOUND');
	}

	/**
	 * @inheritDoc
	 */
	public function updateBalance(): void {
		try {
			$response = $this->api->get('/wallet/total_balance', [
				GateParam::Currency => 'USDT',
			]);

			$total = $response[GateParam::Total][GateParam::Amount] ?? null;
			if ($total === null) {
				$this->logger->error("Failed to get balance: invalid response from Gate");
				return;
			}
			$this->saveBalance(Money::from($total));
		} catch (Exception $e) {
			$this->logger->error("Failed to update balance on Gate: " . $e->getMessage());

			if ($this->isApiKeyError($e)) {
				$this->logger->fatal("Invalid API credentials for Gate. Terminating process to prevent API abuse.", 0);
			}
		}
	}

	/**
	 * Convert internal timeframe to Gate interval format.
	 */
	private function timeframeToGateInterval(TimeFrameEnum $timeframe): string {
		return match ($timeframe->value) {
			'1M' => '30d',
			default => $timeframe->value,
		};
	}

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
		$interval = $this->timeframeToGateInterval($pair->getTimeframe());

		try {
			$query = [GateParam::Interval => $interval];
			$hasTimeRange = ($startTime !== null || $endTime !== null);

			if ($pair->isFutures()) {
				// Gate futures: `limit` conflicts with `from`/`to` — cannot use both.
				if ($hasTimeRange) {
					if ($startTime !== null) {
						$query[GateParam::From] = (string) intdiv($startTime, 1000);
					}
					if ($endTime !== null) {
						$query[GateParam::To] = (string) intdiv($endTime, 1000);
					}
				} else {
					$query[GateParam::Limit] = (string) min($limit, 2000);
				}
				$query[GateParam::Contract] = $ticker;
				$response = $this->api->publicGet("/futures/" . self::SETTLE . "/candlesticks", $query);
			} else {
				// Spot candlesticks allow limit alongside from/to.
				$query[GateParam::Limit] = (string) min($limit, 1000);
				if ($startTime !== null) {
					$query[GateParam::From] = (string) intdiv($startTime, 1000);
				}
				if ($endTime !== null) {
					$query[GateParam::To] = (string) intdiv($endTime, 1000);
				}
				$query[GateParam::CurrencyPair] = $ticker;
				$response = $this->api->publicGet("/spot/candlesticks", $query);
			}

			if (empty($response)) {
				return [];
			}

			if ($pair->isFutures()) {
				$candles = array_map(
					fn(array $item) => new Candle(
						timestamp: (int) $item[GateParam::CandleTime],
						open: (float) $item[GateParam::CandleOpen],
						high: (float) $item[GateParam::CandleHigh],
						low: (float) $item[GateParam::CandleLow],
						close: (float) $item[GateParam::CandleClose],
						volume: (float) $item[GateParam::CandleVolume],
					),
					$response
				);
			} else {
				// Spot candles: array of [timestamp, volume, close, high, low, open, ...]
				$candles = array_map(
					fn(array $item) => new Candle(
						timestamp: (int) $item[GateParam::SpotCandleTime],
						open: (float) $item[GateParam::SpotCandleOpen],
						high: (float) $item[GateParam::SpotCandleHigh],
						low: (float) $item[GateParam::SpotCandleLow],
						close: (float) $item[GateParam::SpotCandleClose],
						volume: (float) $item[GateParam::SpotCandleVolume],
					),
					$response
				);
			}

			usort($candles, fn(Candle $a, Candle $b) => $a->getOpenTime() - $b->getOpenTime());
			return $candles;
		} catch (Exception $e) {
			$this->logger->error("Failed to get candles for $ticker on Gate: " . $e->getMessage());
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
				$response = $this->api->publicGet("/futures/" . self::SETTLE . "/tickers", [
					GateParam::Contract => $ticker,
				]);
				if (!empty($response) && isset($response[0][GateParam::Last])) {
					return Money::from($response[0][GateParam::Last]);
				}
			} else {
				$response = $this->api->publicGet("/spot/tickers", [
					GateParam::CurrencyPair => $ticker,
				]);
				if (!empty($response) && isset($response[0][GateParam::Last])) {
					return Money::from($response[0][GateParam::Last]);
				}
			}

			$this->logger->error("Failed to get current price for $ticker: empty response");
			return null;
		} catch (Exception $e) {
			$this->logger->error("Failed to get current price for $ticker: " . $e->getMessage());
			return null;
		}
	}

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
			$this->logger->error("Cannot open position on Gate: failed to get current price for $market");
			return false;
		}

		// Limit order path.
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
					$this->logger->error("Position opened on Gate but failed to save to DB for $market");
				}
				return true;
			}
			return false;
		}

		// Market order path.
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$entryVolume = $market->calculateQuantity($amount, $currentPrice);
				$contracts = $this->volumeToContracts($market, $entryVolume);

				$body = [
					GateParam::Contract => $ticker,
					GateParam::Size => $direction->isLong() ? $contracts : -$contracts,
					GateParam::Price => '0',
					GateParam::Tif => GateOrderTifEnum::IOC->value,
				];

				$response = $this->api->post("/futures/" . self::SETTLE . "/orders", $body);
			} else {
				$body = [
					GateParam::CurrencyPair => $ticker,
					GateParam::Side => strtolower($direction->getBuySell()),
					GateParam::Type => GateParam::TypeMarket,
					GateParam::Amount => (string) $amount->getAmount(),
				];

				$response = $this->api->post("/spot/orders", $body);
			}

			$orderId = (string) ($response[GateParam::Id] ?? '');
			if (empty($orderId)) {
				$this->logger->error("Failed to open position on Gate for $market: " . json_encode($response));
				return false;
			}

			$this->logger->warning("Successfully opened a position on Gate for $market");

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

				// Gate requires a separate call to set TP.
				$this->setTakeProfit($market, $takeProfitPrice, $direction);
			}

			if ($stopLossPercent && $pair->isFutures()) {
				$stopLossPrice = $currentPrice->modifyByPercentWithDirection(-$stopLossPercent, $direction);
				$position->setExpectedStopLossPercent($stopLossPercent);
				$position->setStopLossPrice($stopLossPrice);

				// Gate requires a separate call to set SL.
				$this->setStopLoss($market, $stopLossPrice, $direction);
			}

			$saved = $position->save();
			if (!$saved) {
				$this->logger->error("Position opened on Gate but failed to save to DB for $market");
			}

			return true;
		} catch (Exception $e) {
			$this->logger->error("Failed to open position on Gate for $market: " . $e->getMessage());
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

				$body = [
					GateParam::Contract => $ticker,
					GateParam::Size => $contracts,
					GateParam::Price => '0',
					GateParam::Tif => GateOrderTifEnum::IOC->value,
				];
				$response = $this->api->post("/futures/" . self::SETTLE . "/orders", $body);
			} else {
				$body = [
					GateParam::CurrencyPair => $ticker,
					GateParam::Side => GateParam::SideBuy,
					GateParam::Type => GateParam::TypeMarket,
					GateParam::Amount => (string) $amount->getAmount(),
				];
				$response = $this->api->post("/spot/orders", $body);
			}

			if (!empty($response[GateParam::Id])) {
				$this->logger->info("Successfully executed DCA buy on Gate for $ticker: $amount");
				return true;
			}

			$this->logger->error("Failed to execute DCA buy on Gate for $ticker: " . json_encode($response));
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to execute DCA buy on Gate for $ticker: " . $e->getMessage());
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

				$body = [
					GateParam::Contract => $ticker,
					GateParam::Size => -$contracts,
					GateParam::Price => '0',
					GateParam::Tif => GateOrderTifEnum::IOC->value,
				];
				$response = $this->api->post("/futures/" . self::SETTLE . "/orders", $body);
			} else {
				$currentPrice = $this->getCurrentPrice($market);
				if ($currentPrice === null) {
					return false;
				}
				$qty = $market->calculateQuantity($amount, $currentPrice);

				$body = [
					GateParam::CurrencyPair => $ticker,
					GateParam::Side => GateParam::SideSell,
					GateParam::Type => GateParam::TypeMarket,
					GateParam::Amount => $qty->formatForOrder($this->getQtyStep($market)),
				];
				$response = $this->api->post("/spot/orders", $body);
			}

			if (!empty($response[GateParam::Id])) {
				$this->logger->info("Successfully executed DCA sell on Gate for $ticker: $amount");
				return true;
			}

			$this->logger->error("Failed to execute DCA sell on Gate for $ticker: " . json_encode($response));
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to execute DCA sell on Gate for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Gate uses "BTC_USDT" format with underscore separator.
	 */
	public static function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency() . '_' . $pair->getQuoteCurrency();
	}

	/**
	 * @inheritDoc
	 */
	public static function tickerToPair(string $exchangeTicker, MarketTypeEnum $marketType): string {
		if (str_contains($exchangeTicker, '_')) {
			return str_replace('_', '/', $exchangeTicker);
		}
		return $exchangeTicker;
	}

	/**
	 * @inheritDoc
	 */
	public function getTopPairsByVolume(int $limit, string $category = 'linear'): array {
		try {
			if ($category === MarketTypeEnum::SPOT->value) {
				$response = $this->api->publicGet("/spot/tickers");
				$marketType = MarketTypeEnum::SPOT;
			} else {
				$response = $this->api->publicGet("/futures/" . self::SETTLE . "/tickers");
				$marketType = MarketTypeEnum::FUTURES;
			}

			if (empty($response)) {
				return [];
			}

			// Sort by 24h quote volume descending.
			usort($response, fn(array $a, array $b) =>
				(float) ($b[GateParam::Turnover24h] ?? $b[GateParam::Volume24hQuote] ?? 0)
				<=> (float) ($a[GateParam::Turnover24h] ?? $a[GateParam::Volume24hQuote] ?? 0)
			);

			$pairs = [];
			foreach ($response as $ticker) {
				$nameField = $category === MarketTypeEnum::SPOT->value ? GateParam::CurrencyPair : GateParam::Contract;
				$symbol = $ticker[$nameField] ?? '';
				if (empty($symbol) || !str_contains($symbol, '_USDT')) {
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
			$this->logger->error("Failed to fetch tickers from Gate: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getSpotBalanceByCurrency(string $coin): Money {
		try {
			$response = $this->api->get("/spot/accounts", [
				GateParam::Currency => $coin,
			]);

			if (!empty($response)) {
				foreach ($response as $account) {
					if (($account[GateParam::Currency] ?? '') === $coin) {
						return Money::from($account[GateParam::Available] ?? '0', $coin);
					}
				}
			}

			return Money::from(0, $coin);
		} catch (Exception $e) {
			$this->logger->error("Failed to get spot balance for $coin on Gate: " . $e->getMessage());
			return Money::from(0, $coin);
		}
	}

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
			if ($this->isDualMode()) {
				$response = $this->api->get("/futures/" . self::SETTLE . "/dual_comp/positions/$ticker");
				foreach ($response as $posInfo) {
					if (((int) ($posInfo[GateParam::Size] ?? 0)) !== 0) {
						$posInfo = $this->enrichPositionWithContractInfo($posInfo, $ticker);
						return PositionOnGate::create($market, $posInfo);
					}
				}
			} else {
				$response = $this->api->get("/futures/" . self::SETTLE . "/positions/$ticker");
				if (((int) ($response[GateParam::Size] ?? 0)) !== 0) {
					$response = $this->enrichPositionWithContractInfo($response, $ticker);
					return PositionOnGate::create($market, $response);
				}
			}

			return false;
		} catch (Exception $e) {
			if (!$this->isPositionNotFound($e)) {
				$this->logger->error("Failed to get futures position for $ticker on Gate: " . $e->getMessage());
			}
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentFuturesPositionByDirection(IMarket $market, PositionDirectionEnum $direction): IPositionOnExchange|false {
		$pair = $market->getPair();
		if (!$pair->isFutures()) {
			$this->logger->error("Trying to get futures position on spot market: $market");
			return false;
		}

		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($this->isDualMode()) {
				$response = $this->api->get("/futures/" . self::SETTLE . "/dual_comp/positions/$ticker");
				foreach ($response as $posInfo) {
					$size = (int) ($posInfo[GateParam::Size] ?? 0);
					if ($size === 0) {
						continue;
					}
					$posDirection = $size > 0 ? PositionDirectionEnum::LONG : PositionDirectionEnum::SHORT;
					if ($posDirection === $direction) {
						$posInfo = $this->enrichPositionWithContractInfo($posInfo, $ticker);
						return PositionOnGate::create($market, $posInfo);
					}
				}
			} else {
				$response = $this->api->get("/futures/" . self::SETTLE . "/positions/$ticker");
				$size = (int) ($response[GateParam::Size] ?? 0);
				if ($size !== 0) {
					$posDirection = $size > 0 ? PositionDirectionEnum::LONG : PositionDirectionEnum::SHORT;
					if ($posDirection === $direction) {
						$response = $this->enrichPositionWithContractInfo($response, $ticker);
						return PositionOnGate::create($market, $response);
					}
				}
			}

			return false;
		} catch (Exception $e) {
			if (!$this->isPositionNotFound($e)) {
				$this->logger->error("Failed to get futures position by direction for $ticker on Gate: " . $e->getMessage());
			}
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

				$body = [
					GateParam::Contract => $ticker,
					GateParam::Size => $direction->isLong() ? $contracts : -$contracts,
					GateParam::Price => $price->formatForOrder($this->getTickSize($market)),
					GateParam::Tif => GateOrderTifEnum::GTC->value,
				];

				$response = $this->api->post("/futures/" . self::SETTLE . "/orders", $body);
			} else {
				$body = [
					GateParam::CurrencyPair => $ticker,
					GateParam::Side => strtolower($direction->getBuySell()),
					GateParam::Type => GateParam::TypeLimit,
					GateParam::Amount => $amount->formatForOrder($this->getQtyStep($market)),
					GateParam::Price => $price->formatForOrder($this->getTickSize($market)),
				];

				$response = $this->api->post("/spot/orders", $body);
			}

			$orderId = (string) ($response[GateParam::Id] ?? '');
			if (!empty($orderId)) {
				// Gate does not support TP in the order itself; set separately.
				if ($takeProfitPercent && $pair->isFutures()) {
					$takeProfitPrice = $price->modifyByPercentWithDirection($takeProfitPercent, $direction);
					$this->setTakeProfit($market, $takeProfitPrice, $direction);
				}
				return $orderId;
			}

			$this->logger->error("Failed to place limit order on Gate for $market: " . json_encode($response));
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to place limit order on Gate for $market: " . $e->getMessage());
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
				$this->api->delete("/futures/" . self::SETTLE . "/orders", [
					GateParam::Contract => $ticker,
				]);
			} else {
				$this->api->delete("/spot/orders", [
					GateParam::CurrencyPair => $ticker,
				]);
			}
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to remove limit orders on Gate for $ticker: " . $e->getMessage());
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
				$orders = $this->api->get("/futures/" . self::SETTLE . "/orders", [
					GateParam::Contract => $ticker,
					GateParam::Status => GateOrderStatusEnum::Open->value,
				]);

				foreach ($orders as $order) {
					$orderSize = (int) ($order[GateParam::Size] ?? 0);
					$orderDir = $orderSize > 0 ? PositionDirectionEnum::LONG : PositionDirectionEnum::SHORT;
					if ($orderDir !== $direction) {
						continue;
					}
					$this->api->delete("/futures/" . self::SETTLE . "/orders/" . $order[GateParam::Id]);
				}
			} else {
				$orders = $this->api->get("/spot/orders", [
					GateParam::CurrencyPair => $ticker,
					GateParam::Status => GateOrderStatusEnum::Open->value,
				]);

				$targetSide = strtolower($direction->getBuySell());
				foreach ($orders as $order) {
					if (($order[GateParam::Side] ?? '') !== $targetSide) {
						continue;
					}
					$this->api->delete("/spot/orders/" . $order[GateParam::Id], [
						GateParam::CurrencyPair => $ticker,
					]);
				}
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to remove limit orders by direction on Gate for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Gate uses price_orders (conditional orders) for TP.
	 */
	public function setTakeProfit(IMarket $market, Money $expectedPrice, PositionDirectionEnum $direction): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			// TP trigger: for long, trigger when price >= TP; for short, when price <= TP.
			$rule = $direction->isLong() ? 1 : 2;

			// Cancel only existing TP orders (same rule), leave SL intact.
			$this->cancelPriceOrdersByRule($ticker, $direction, $rule);

			$isDualMode = $this->isDualMode();
			$autoSize = GateAutoSizeEnum::fromDirection($direction);

			$body = [
				GateParam::Initial => [
					GateParam::Contract => $ticker,
					GateParam::Size => 0,
					GateParam::Price => '0',
					GateParam::Tif => GateOrderTifEnum::IOC->value,
					GateParam::Close => !$isDualMode,
					GateParam::ReduceOnly => true,
					GateParam::AutoSize => $isDualMode ? $autoSize->value : '',
				],
				GateParam::Trigger => [
					GateParam::StrategyType => 0,
					GateParam::PriceType => 0,
					GateParam::Price => $expectedPrice->formatForOrder($this->getTickSize($market)),
					GateParam::Rule => $rule,
					GateParam::Expiration => 86400,
				],
				GateParam::OrderType => GatePositionCloseTypeEnum::entireClose($direction)->value,
			];

			$this->api->post("/futures/" . self::SETTLE . "/price_orders", $body);
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to set TP for $ticker on Gate: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Gate uses price_orders (conditional orders) for SL.
	 */
	public function setStopLoss(IMarket $market, Money $expectedPrice, PositionDirectionEnum $direction): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			// SL trigger: for long, trigger when price <= SL; for short, when price >= SL.
			$rule = $direction->isLong() ? 2 : 1;

			// Cancel only existing SL orders (same rule), leave TP intact.
			$this->cancelPriceOrdersByRule($ticker, $direction, $rule);

			$isDualMode = $this->isDualMode();
			$autoSize = GateAutoSizeEnum::fromDirection($direction);

			$body = [
				GateParam::Initial => [
					GateParam::Contract => $ticker,
					GateParam::Size => 0,
					GateParam::Price => '0',
					GateParam::Tif => GateOrderTifEnum::IOC->value,
					GateParam::Close => !$isDualMode,
					GateParam::ReduceOnly => true,
					GateParam::AutoSize => $isDualMode ? $autoSize->value : '',
				],
				GateParam::Trigger => [
					GateParam::StrategyType => 0,
					GateParam::PriceType => 0,
					GateParam::Price => $expectedPrice->formatForOrder($this->getTickSize($market)),
					GateParam::Rule => $rule,
					GateParam::Expiration => 86400,
				],
				GateParam::OrderType => GatePositionCloseTypeEnum::entireClose($direction)->value,
			];

			$this->api->post("/futures/" . self::SETTLE . "/price_orders", $body);
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to set SL for $ticker on Gate: " . $e->getMessage());
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
					$this->logger->error("Cannot partial close on Gate: no position found for $ticker");
					return false;
				}
				$direction = $position->getDirection();
			}

			$contracts = $this->volumeToContracts($market, $volume);
			// Closing long = negative size, closing short = positive size.
			$closeSize = $direction->isLong() ? -$contracts : $contracts;

			$body = [
				GateParam::Contract => $ticker,
				GateParam::Size => $closeSize,
				GateParam::Price => '0',
				GateParam::Tif => GateOrderTifEnum::IOC->value,
				GateParam::ReduceOnly => true,
			];

			if ($this->isDualMode()) {
				$body[GateParam::AutoSize] = GateAutoSizeEnum::fromDirection($direction)->value;
			}

			$response = $this->api->post("/futures/" . self::SETTLE . "/orders", $body);

			if (!empty($response[GateParam::Id])) {
				$this->logger->info("Partial close on Gate for $ticker: closed {$volume->format()}");
				return true;
			}

			$this->logger->error("Failed to partial close on Gate for $ticker: " . json_encode($response));
			return false;
		} catch (Throwable $e) {
			$this->logger->error("Failed to partial close on Gate for $ticker: " . $e->getMessage());
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
			$closeSize = $direction->isLong() ? -$contracts : $contracts;

			$body = [
				GateParam::Contract => $ticker,
				GateParam::Size => $closeSize,
				GateParam::Price => $price->formatForOrder($this->getTickSize($market)),
				GateParam::Tif => GateOrderTifEnum::GTC->value,
				GateParam::ReduceOnly => true,
			];

			if ($this->isDualMode()) {
				$body[GateParam::AutoSize] = GateAutoSizeEnum::fromDirection($direction)->value;
			}

			$response = $this->api->post("/futures/" . self::SETTLE . "/orders", $body);

			$orderId = (string) ($response[GateParam::Id] ?? '');
			if (!empty($orderId)) {
				$this->logger->info("Placed limit close on Gate for $ticker: {$volume->format()} @ {$price->format()}");
				return $orderId;
			}

			$this->logger->error("Failed to place limit close on Gate for $ticker: " . json_encode($response));
			return false;
		} catch (Throwable $e) {
			$this->logger->error("Failed to place limit close on Gate for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getOrderById(IMarket $market, string $orderIdOnExchange): Order|false {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			if ($pair->isFutures()) {
				$response = $this->api->get("/futures/" . self::SETTLE . "/orders/$orderIdOnExchange");
			} else {
				$response = $this->api->get("/spot/orders/$orderIdOnExchange", [
					GateParam::CurrencyPair => $ticker,
				]);
			}

			if (empty($response) || !isset($response[GateParam::Id])) {
				return false;
			}

			return $this->buildOrderFromResponse($response, $pair->isFutures());
		} catch (Throwable $e) {
			$this->logger->error("Failed to get order $orderIdOnExchange on Gate: " . $e->getMessage());
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

	/**
	 * @inheritDoc
	 */
	public function getMarginMode(IMarket $market): ?MarginModeEnum {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		if (isset($this->marginModeCache[$ticker])) {
			return $this->marginModeCache[$ticker];
		}

		try {
			$response = $this->api->get("/futures/" . self::SETTLE . "/positions/$ticker");

			// Cross vs isolated is determined by leverage: 0 = cross.
			$leverage = (int) ($response[GateParam::Leverage] ?? 0);
			$marginMode = ($leverage === 0) ? MarginModeEnum::CROSS : MarginModeEnum::ISOLATED;

			$this->marginModeCache[$ticker] = $marginMode;
			return $marginMode;
		} catch (Throwable $e) {
			if ($this->isPositionNotFound($e)) {
				$this->marginModeCache[$ticker] = MarginModeEnum::CROSS;
				return MarginModeEnum::CROSS;
			}
			$this->logger->error("Failed to get margin mode for $ticker on Gate: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function switchMarginMode(IMarket $market, MarginModeEnum $mode): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		if (isset($this->marginModeCache[$ticker]) && $this->marginModeCache[$ticker] === $mode) {
			return true;
		}

		try {
			$currentLeverage = $this->getLeverage($market) ?? 10;
			$newLeverage = $mode->isIsolated() ? $currentLeverage : 0;

			$this->api->post("/futures/" . self::SETTLE . "/positions/$ticker/leverage", [
				GateParam::Leverage => (string) $newLeverage,
			]);

			$this->logger->info("Switched margin mode to {$mode->getLabel()} for $ticker on Gate");
			$this->marginModeCache[$ticker] = $mode;
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to switch margin mode for $ticker on Gate: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getLeverage(IMarket $market): ?float {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		try {
			$response = $this->api->get("/futures/" . self::SETTLE . "/positions/$ticker");
			$leverage = (float) ($response[GateParam::Leverage] ?? 0);
			// Gate uses 0 for cross margin; return actual leverage or null.
			return $leverage > 0 ? $leverage : null;
		} catch (Throwable $e) {
			if (!$this->isPositionNotFound($e)) {
				$this->logger->error("Failed to get leverage for $ticker on Gate: " . $e->getMessage());
			}
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getPositionMode(IMarket $market): ?PositionModeEnum {
		return $this->isDualMode() ? PositionModeEnum::HEDGE : PositionModeEnum::ONE_WAY;
	}

	/**
	 * @inheritDoc
	 */
	public function getQtyStep(IMarket $market): string {
		$info = $this->getContractInfo($market);
		if ($info === null) {
			return '1';
		}
		return $info[GateParam::QuantoMultiplier] ?? '1';
	}

	/**
	 * @inheritDoc
	 */
	public function getTickSize(IMarket $market): string {
		$info = $this->getContractInfo($market);
		if ($info === null) {
			return '0.0001';
		}
		return $info[GateParam::OrderPriceRound] ?? '0.0001';
	}

	/**
	 * @inheritDoc
	 */
	public function getTakerFee(MarketTypeEnum $marketType): float {
		return match ($marketType) {
			MarketTypeEnum::FUTURES => 0.0005,
			MarketTypeEnum::SPOT => 0.002,
		};
	}

	/**
	 * @inheritDoc
	 */
	public function getMakerFee(MarketTypeEnum $marketType): float {
		return match ($marketType) {
			MarketTypeEnum::FUTURES => 0.00015,
			MarketTypeEnum::SPOT => 0.002,
		};
	}

	// ───────────────────────── Private helpers ─────────────────────────

	/**
	 * Get contract specification for a futures market (cached).
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
				$response = $this->api->publicGet("/futures/" . self::SETTLE . "/contracts/$ticker");
			} else {
				$response = $this->api->publicGet("/spot/currency_pairs/$ticker");
			}

			$this->contractInfoCache[$ticker] = $response;
			return $response;
		} catch (Throwable $e) {
			$this->logger->error("Failed to get contract info for $ticker on Gate: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Convert base currency volume to Gate contract count.
	 *
	 * Gate futures use integer contract sizes. Each contract represents
	 * quanto_multiplier units of the base currency.
	 */
	private function volumeToContracts(IMarket $market, Money $volume): int {
		$info = $this->getContractInfo($market);
		$multiplier = (float) ($info[GateParam::QuantoMultiplier] ?? 1);
		$contracts = (int) floor($volume->getAmount() / $multiplier);
		return max($contracts, 1);
	}

	/**
	 * Check if the account is in dual (hedge) position mode.
	 *
	 * Gate has no GET endpoint for dual_mode; the current state is
	 * available via the futures account info (in_dual_mode field).
	 */
	private function isDualMode(): bool {
		if ($this->dualModeCache !== null) {
			return $this->dualModeCache;
		}

		try {
			$response = $this->api->get("/futures/" . self::SETTLE . "/accounts");
			$this->dualModeCache = (bool) ($response[GateParam::InDualMode] ?? false);
		} catch (Throwable $e) {
			$this->logger->warning("Failed to check dual mode on Gate: " . $e->getMessage());
			$this->dualModeCache = false;
		}

		return $this->dualModeCache;
	}

	/**
	 * Enrich position info with quanto_multiplier from contract specs.
	 */
	private function enrichPositionWithContractInfo(array $posInfo, string $ticker): array {
		$contractInfo = $this->contractInfoCache[$ticker] ?? null;
		if ($contractInfo === null) {
			try {
				$contractInfo = $this->api->publicGet("/futures/" . self::SETTLE . "/contracts/$ticker");
				$this->contractInfoCache[$ticker] = $contractInfo;
			} catch (Throwable) {
				return $posInfo;
			}
		}
		$posInfo[GateParam::QuantoMultiplier] = $contractInfo[GateParam::QuantoMultiplier] ?? '1';
		$posInfo[GateParam::Contract] = $ticker;
		return $posInfo;
	}

	/**
	 * Build an Order object from Gate API order response.
	 */
	private function buildOrderFromResponse(array $orderInfo, bool $isFutures): Order {
		$order = new Order();
		$order->setIdOnExchange((string) ($orderInfo[GateParam::Id] ?? ''));

		if ($isFutures) {
			$left = (int) ($orderInfo[GateParam::Left] ?? 0);
			$size = abs((int) ($orderInfo[GateParam::Size] ?? 0));
			$filled = $size - $left;
			$order->setVolume(Money::from($filled));
		} else {
			$order->setVolume(Money::from($orderInfo[GateParam::Amount] ?? '0'));
		}

		$gateStatus = $orderInfo[GateParam::Status] ?? GateOrderStatusEnum::Finished->value;
		$order->setStatus($this->mapGateOrderStatus($gateStatus, $orderInfo));

		$price = $orderInfo[GateParam::Price] ?? '0';
		$orderType = ($price === '0' || $price === '') ? OrderTypeEnum::MARKET : OrderTypeEnum::LIMIT;
		$order->setOrderType($orderType);

		return $order;
	}

	/**
	 * Map Gate order status to internal OrderStatusEnum.
	 */
	private function mapGateOrderStatus(string $status, array $orderInfo): OrderStatusEnum {
		return match ($status) {
			GateOrderStatusEnum::Open->value => match (true) {
				((int) ($orderInfo[GateParam::Left] ?? 0)) < abs((int) ($orderInfo[GateParam::Size] ?? 0))
					=> OrderStatusEnum::PartiallyFilled,
				default => OrderStatusEnum::NewOrder,
			},
			GateOrderStatusEnum::Finished->value => match ($orderInfo[GateParam::FinishAs] ?? GateFinishAsEnum::Filled->value) {
				GateFinishAsEnum::Filled->value => OrderStatusEnum::Filled,
				GateFinishAsEnum::Cancelled->value => OrderStatusEnum::Cancelled,
				GateFinishAsEnum::IOC->value => OrderStatusEnum::Cancelled,
				GateFinishAsEnum::ReduceOnly->value => OrderStatusEnum::Cancelled,
				default => OrderStatusEnum::Filled,
			},
			default => OrderStatusEnum::NewOrder,
		};
	}

	/**
	 * Cancel existing price orders matching a specific trigger rule for a direction.
	 *
	 * Gate uses the same order_type for both TP and SL; the only distinguishing
	 * factor is the trigger rule (1 = price >=, 2 = price <=).
	 *
	 * @param string $ticker Exchange ticker.
	 * @param PositionDirectionEnum $direction Position direction.
	 * @param int $triggerRule Trigger rule to match (1 or 2).
	 */
	private function cancelPriceOrdersByRule(string $ticker, PositionDirectionEnum $direction, int $triggerRule): void {
		try {
			$orders = $this->api->get("/futures/" . self::SETTLE . "/price_orders", [
				GateParam::Contract => $ticker,
				GateParam::Status => GateOrderStatusEnum::Open->value,
			]);

			$targetOrderTypes = GatePositionCloseTypeEnum::allValuesForDirection($direction);

			foreach ($orders as $order) {
				if (!in_array($order[GateParam::OrderType] ?? '', $targetOrderTypes, true)) {
					continue;
				}
				$orderRule = (int) ($order[GateParam::Trigger][GateParam::Rule] ?? 0);
				if ($orderRule === $triggerRule) {
					$this->api->delete("/futures/" . self::SETTLE . "/price_orders/" . $order[GateParam::Id]);
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				"Failed to cancel existing price orders on Gate for $ticker "
				. "(direction={$direction->value}, trigger_rule=$triggerRule): "
				. $e->getMessage()
			);
		}
	}
}
