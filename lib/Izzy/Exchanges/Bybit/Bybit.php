<?php

namespace Izzy\Exchanges\Bybit;

use ByBit\SDK\ByBitApi;
use ByBit\SDK\Enums\AccountType;
use ByBit\SDK\Exceptions\HttpException;
use Exception;
use InvalidArgumentException;
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
use Izzy\Financial\Pair;
use Izzy\Financial\StoredPosition;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPositionOnExchange;
use Throwable;

/**
 * Driver for working with Bybit exchange.
 */
class Bybit extends AbstractExchangeDriver
{
	/** @var string Exchange name identifier. */
	protected string $exchangeName = 'Bybit';

	/** @var ByBitApi API instance for communication with the exchange. */
	protected ByBitApi $api;

	/**
	 * List of the markets being traded on / monitored.
	 * @var IMarket[]
	 */
	protected array $markets = [];

	/**
	 * Cached quantity steps.
	 * @var array
	 */
	protected array $qtySteps = [];

	/**
	 * Cached tick sizes.
	 * @var array
	 */
	protected array $tickSizes = [];

	/**
	 * Cached margin modes per symbol after a successful switch.
	 * Prevents repeated API calls when the exchange driver already
	 * knows the mode was changed during this session.
	 * Key: symbol string, value: MarginModeEnum.
	 * @var array<string, MarginModeEnum>
	 */
	protected array $marginModeCache = [];

	/**
	 * @inheritDoc
	 */
	public function connect(): bool {
		try {
			$key = $this->config->getKey();
			$secret = $this->config->getSecret();
			$this->api = new ByBitApi($key, $secret, ByBitApi::PROD_API_URL);
			return true;
		} catch (Exception $e) {
			$this->logger->error("Failed to connect to exchange $this->exchangeName: ".$e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function disconnect(): void {
		die();
	}

	/**
	 * Check if an exception represents an API key or access error
	 * that will not resolve itself (invalid key, expired key, wrong IP, etc.).
	 *
	 * @param Exception $e Exception to check.
	 * @return bool True if the exception is a permanent credential/access error, false otherwise.
	 */
	private function isApiKeyError(Exception $e): bool {
		$lowerMessage = strtolower($e->getMessage());

		// Invalid or expired API key.
		if (str_contains($lowerMessage, 'api key') &&
			(str_contains($lowerMessage, 'invalid') || str_contains($lowerMessage, 'expired'))) {
			return true;
		}

		// IP address not whitelisted for this API key.
		if (str_contains($lowerMessage, 'unmatched ip')) {
			return true;
		}

		// Generic authentication failures.
		if (str_contains($lowerMessage, 'authentication') ||
			str_contains($lowerMessage, 'unauthorized') ||
			str_contains($lowerMessage, 'api key invalid')) {
			return true;
		}

		return false;
	}

	/**
	 * @inheritDoc
	 *
	 * NOTE: Earn API is not implemented in the SDK.
	 */
	public function updateBalance(): void {
		try {
			$params = [BybitParam::AccountType => AccountType::UNIFIED];
			$info = $this->api->accountApi()->getWalletBalance($params);
			if (!isset($info[BybitParam::List][0][BybitParam::TotalEquity])) {
				$this->logger->error("Failed to get balance: invalid response format from Bybit");
				return;
			}
			$totalBalance = Money::from($info[BybitParam::List][0][BybitParam::TotalEquity]);
			$this->saveBalance($totalBalance);
		} catch (Exception $e) {
			$this->logger->error("Unexpected error while updating balance on $this->exchangeName: ".$e->getMessage());

			if ($this->isApiKeyError($e)) {
				$this->logger->fatal("Invalid API credentials for {$this->exchangeName}. Terminating process to prevent API abuse.");
			}
		}
	}

	/**
	 * Convert internal timeframe to Bybit interval format.
	 *
	 * @param TimeFrameEnum $timeframe Internal timeframe enum.
	 * @return string|null Bybit interval string or null if not supported.
	 */
	private function timeframeToBybitInterval(TimeFrameEnum $timeframe): ?string {
		return match ($timeframe->value) {
			'1m' => '1',
			'3m' => '3',
			'5m' => '5',
			'15m' => '15',
			'30m' => '30',
			'1h' => '60',
			'2h' => '120',
			'4h' => '240',
			'6h' => '360',
			'12h' => '720',
			'1d' => 'D',
			'1w' => 'W',
			'1M' => 'M',
			default => null,
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
		try {
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $ticker,
				BybitParam::Interval => $this->timeframeToBybitInterval($pair->getTimeframe()),
				BybitParam::Limit => $limit
			];

			if ($startTime !== null)
				$params[BybitParam::Start] = $startTime;
			if ($endTime !== null)
				$params[BybitParam::End] = $endTime;

			$response = $this->api->marketApi()->getKline($params);

			if (empty($response[BybitParam::List])) {
				return []; // No candles received.
			}

			$candles = array_map(
				callback: fn($item) => new Candle(
					timestamp: (int)($item[0] / 1000), // convert from milliseconds to seconds.
					open: (float)$item[1],
					high: (float)$item[2],
					low: (float)$item[3],
					close: (float)$item[4],
					volume: (float)$item[5]
				),
				array: $response[BybitParam::List]
			);

			// Sort candles by time (oldest to newest).
			usort($candles, fn($a, $b) => $a->getOpenTime() - $b->getOpenTime());

			return $candles;
		} catch (HttpException $exception) {
			$this->logger->error("Failed to get candles for $ticker on $this->exchangeName: ".$exception->getMessage());
			return [];
		} catch (Exception $e) {
			$this->logger->error("Unexpected error while getting candles for $ticker on $this->exchangeName: ".$e->getMessage());
			return [];
		}
	}

	/**
	 * Get Bybit category for a trading pair.
	 *
	 * @param Pair $pair Trading pair.
	 * @return string Bybit category string.
	 * @throws InvalidArgumentException If pair type is unknown.
	 */
	private function getBybitCategory(Pair $pair): string {
		if ($pair->isSpot()) {
			return 'spot';
		} elseif ($pair->isFutures()) {
			return 'linear';
		} elseif ($pair->isInverseFutures()) {
			return 'inverse';
		} else {
			throw new InvalidArgumentException("Unknown pair type for Bybit: ".$pair->getMarketType()->toString());
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(IMarket $market): ?Money {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $ticker
			];

			$response = $this->api->marketApi()->getTickers($params);

			if (empty($response[BybitParam::List])) {
				$this->logger->error("Failed to get current price for $ticker: empty response");
				return null;
			}

			// Find the ticker in the response
			foreach ($response[BybitParam::List] as $tickerData) {
				if ($tickerData[BybitParam::Symbol] === $ticker) {
					return Money::from($tickerData[BybitParam::LastPrice]);
				}
			}

			$this->logger->error("Ticker $ticker not found in response");
			return null;
		} catch (Exception $e) {
			$this->logger->error("Failed to get current price for $ticker: ".$e->getMessage());
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
	): bool {
		// Current price.
		$currentPrice = $this->getCurrentPrice($market);

		// If the price is not null, we want a limit order.
		if (!is_null($price)) {
			$orderIdOnExchange = $this->placeLimitOrder(
				market: $market,
				amount: $amount,
				price: $price,
				direction: $direction,
				takeProfitPercent: $takeProfitPercent
			);
			if ($orderIdOnExchange) {
				/**
				 * Create and save the position. For the spot market, positions are emulated.
				 * So, we use a StoredPosition instead of PositionOnBybit here.
				 */
				$position = StoredPosition::create(
					market: $market,
					volume: $amount,
					direction: $direction,
					entryPrice: $currentPrice,
					currentPrice: $currentPrice,
					status: PositionStatusEnum::OPEN,
					exchangePositionId: $orderIdOnExchange
				);

				// Store TP metadata on the position object.
				// The actual TP order was already included in the placeLimitOrder params,
				// so there is no need to call setTakeProfit() again.
				if ($takeProfitPercent) {
					$takeProfitPrice = $price->modifyByPercentWithDirection($takeProfitPercent, $direction);
					$position->setExpectedProfitPercent($takeProfitPercent);
					$position->setTakeProfitPrice($takeProfitPrice);
				}

				$position->setAverageEntryPrice($currentPrice);

				// Save the position to the database.
				$saved = $position->save();
				if (!$saved) {
					$this->logger->error("Position opened on Bybit but failed to save to DB for $market");
				}

				// Success.
				return true;
			}
		}

		// Pair and ticker for this Exchange.
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		// Params to be sent to Bybit.
		$params = [
			BybitParam::Category => $this->getBybitCategory($pair),
			BybitParam::Symbol => $ticker,
			BybitParam::Side => $direction->getBuySell(),
			BybitParam::OrderType => OrderTypeEnum::MARKET->value,
		];

		// Amount is always in quote currency (e.g., USDT).
		// We need to convert it to base currency (e.g., BTC, SOL) for the API call.
		$entryVolume = $market->calculateQuantity(Money::from($amount), $currentPrice);

		// Amount should be adjusted using QtyStep.
		$properVolume = $entryVolume->formatForOrder($this->getQtyStep($market));

		// Adding to the params for the API call.
		$params[BybitParam::Qty] = $properVolume;

		if ($market->isFutures()) {
			// If we have a TP defined.
			if ($takeProfitPercent) {
				$takeProfitPrice = $currentPrice->modifyByPercentWithDirection($takeProfitPercent, $direction);
				$params[BybitParam::TakeProfit] = $takeProfitPrice->formatForOrder($this->getTickSize($market));
			}

			$params[BybitParam::PositionIdx] = $this->getPositionIdxByDirection($direction);
			if ($direction->isShort()) {
				$params[BybitParam::IsReduceOnly] = false;
			}
		}

		// NOTE: For spot, we cannot assign TP in the same API call.

		try {
			// Make an API call.
			$response = $this->api->tradeApi()->placeOrder($params);

			if (!isset($response[BybitParam::OrderId])) {
				$this->logger->error("Failed to open a position on $market: ".json_encode($response));
				return false;
			}

			// Inform the user.
			$this->logger->warning("Successfully opened a position on Bybit for $market: $properVolume");

			/**
			 * Create and save the position. For the spot market, positions are emulated.
			 * So, we use a StoredPosition instead of PositionOnBybit here.
			 * Volume is stored in base currency (the actual qty sent to the exchange).
			 */
			$position = StoredPosition::create(
				market: $market,
				volume: $entryVolume,
				direction: $direction,
				entryPrice: $currentPrice,
				currentPrice: $currentPrice,
				status: PositionStatusEnum::OPEN,
				exchangePositionId: $response[BybitParam::OrderId]
			);
			$position->setAverageEntryPrice($currentPrice);

			// Store TP metadata on the position object.
			// The actual TP order was already included in the placeOrder params above,
			// so there is no need to call setTakeProfit() again (Bybit returns "not modified").
			if ($takeProfitPercent) {
				$position->setExpectedProfitPercent($takeProfitPercent);
				$takeProfitPrice = $currentPrice->modifyByPercentWithDirection($takeProfitPercent, $direction);
				$position->setTakeProfitPrice($takeProfitPrice);
			}

			// Save the position to the database.
			$saved = $position->save();
			if (!$saved) {
				$this->logger->error("Position opened on Bybit but failed to save to DB for $market");
			}

			// Success (the order was placed on the exchange regardless of local DB save).
			return true;
		} catch (Exception $e) {
			// Inform the user.
			$this->logger->error("Failed to open a position on $market: ".$e->getMessage());

			// Failure.
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool {
		// TODO: Implement buyAdditional for DCA averaging.
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function sellAdditional(IMarket $market, Money $amount): bool {
		// TODO
		return false;
	}

	/**
	 * @inheritDoc
	 *
	 * Bybit uses tickers like "BTCUSDT" for pairs.
	 */
	public static function pairToTicker(IPair $pair): string {
		$multiplier = '';
		if ($pair->isFutures()) {
			$baseCurrency = $pair->getBaseCurrency();
			switch ($baseCurrency) {
				case 'PEPE':
				case 'RATS':
				case 'BONK':
				case 'CAT':
				case 'FLOKI':
				case 'LUNC':
				case 'TOSHI':
				case 'TURBO':
				case 'XEC':
					$multiplier = '1000';
					break;
				case 'SATS':
					$multiplier = '10000';
					break;
				case 'BABYDOGE':
					$multiplier = '1000000';
					break;
				case 'SHIB':
					return 'SHIB1000USDT';
					break;
			}
		}
		return $multiplier . $pair->getBaseCurrency() . $pair->getQuoteCurrency();
	}

	/**
	 * @inheritDoc
	 */
	public function getOrderById(IMarket $market, string $orderIdOnExchange): Order|false {
		$pair = $market->getPair();
		$params = [
			BybitParam::Category => $this->getBybitCategory($pair),
			BybitParam::Symbol => $market->getPair()->getExchangeTicker($this),
			BybitParam::OrderId => $orderIdOnExchange,
		];
		$response = $this->api->tradeApi()->getOpenOrders($params);

		// If there is no order list in the response, there was probably no such order.
		if (!isset($response[BybitParam::List]))
			return false;

		// But if it's empty, we also think that there was no such order.
		if (empty($response[BybitParam::List]))
			return false;

		// Order info is an array. Let's build an object for convenience.
		$orderInfo = $response[BybitParam::List][0];
		return $this->orderInfoToObject($orderInfo);
	}

	/**
	 * @inheritDoc
	 */
	public function hasActiveOrder(IMarket $market, string $orderIdOnExchange): bool {
		$order = $this->getOrderById($market, $orderIdOnExchange);
		return ($order !== false) && $order->isActive();
	}

	/**
	 * Create an Order object from Bybit-specific order info array.
	 * @param array $orderInfo
	 * @return Order
	 */
	private function orderInfoToObject(array $orderInfo): Order {
		$order = new Order();
		$order->setVolume(Money::from($orderInfo['cumExecQty']));
		$order->setOrderType(OrderTypeEnum::from($orderInfo[BybitParam::OrderType]));
		$order->setStatus($this->getOrderStatusFromInfoString($orderInfo['orderStatus']));
		$order->setIdOnExchange($orderInfo[BybitParam::OrderId]);
		return $order;
	}

	/**
	 * Get an OrderTypeEnum from Bybit-specific order info element.
	 * @param mixed $orderStatus
	 * @return OrderStatusEnum
	 */
	private function getOrderStatusFromInfoString(string $orderStatus): OrderStatusEnum {
		return match ($orderStatus) {
			'New' => OrderStatusEnum::NewOrder,
			'PartiallyFilled' => OrderStatusEnum::PartiallyFilled,
			'Filled' => OrderStatusEnum::Filled,
			'Cancelled' => OrderStatusEnum::Cancelled,
			'Rejected' => OrderStatusEnum::Rejected,
			'Untriggered' => OrderStatusEnum::Untriggered,
			'Triggered' => OrderStatusEnum::Triggered,
			'Deactivated' => OrderStatusEnum::Deactivated,
			'PartiallyFilledCanceled' => OrderStatusEnum::PartiallyFilledCanceled,
		};
	}

	/**
	 * @inheritDoc
	 */
	public function getSpotBalanceByCurrency(string $coin): Money {
		$params = [BybitParam::AccountType => 'UNIFIED', BybitParam::Coin => $coin];
		$response = $this->api->assetApi()->getSingleCoinBalance($params);
		$balanceInfo = $response[BybitParam::Balance];
		return Money::from($balanceInfo[BybitParam::WalletBalance], $coin);
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentFuturesPosition(IMarket $market): IPositionOnExchange|false {
		$pair = $market->getPair();
		$marketType = $market->getMarketType();
		if (!$marketType->isFutures()) {
			$this->logger->error("Trying to get a futures position on a spot market: $market");
			return false;
		}

		$params = [
			BybitParam::Category => $this->getBybitCategory($pair),
			BybitParam::Symbol => $pair->getExchangeTicker($this),
		];

		$response = $this->api->positionApi()->getPositionInfo($params);
		$positionList = $response[BybitParam::List];

		/*
		 * Bybit always returns 2 positions in 1 list for two-way mode.
		 * Long has positionIdx = 1, Short has positionIdx = 2.
		 */
		foreach ($positionList as $positionInfo) {
			// Skip empty positions.
			if (Money::from($positionInfo[BybitParam::Size], $pair->getBaseCurrency())?->isZero())
				continue;
			return PositionOnBybit::create($market, $positionInfo);
		}

		return false;
	}

	/**
	 * @inheritDoc
	 *
	 * NOTE: We cannot detect TP order Id at this point.
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
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $ticker,
				BybitParam::Side => $direction->getBuySell(),
				BybitParam::OrderType => OrderTypeEnum::LIMIT->value,
				BybitParam::Qty => $amount->formatForOrder($this->getQtyStep($market)),
				BybitParam::Price => $price->formatForOrder($this->getTickSize($market)),
				BybitParam::PositionIdx => $this->getPositionIdxByDirection($direction),
			];

			// If we have a TP defined.
			if ($takeProfitPercent) {
				$takeProfitPrice = $price->modifyByPercentWithDirection($takeProfitPercent, $direction);
				$params[BybitParam::TakeProfit] = $takeProfitPrice->formatForOrder($this->getTickSize($market));
			}

			// Make an API call.
			$response = $this->api->tradeApi()->placeOrder($params);

			if (isset($response[BybitParam::OrderId])) {
				return $response[BybitParam::OrderId];
			} else {
				$this->logger->error("Failed to place a limit order on $market: ".json_encode($response));
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to place a limit order on $market: ".$e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 *
	 * TODO: Fetch instrument info when initializing Market for better performance.
	 */
	public function getQtyStep(IMarket $market): string {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		// We already have it cached.
		if (isset($this->qtySteps[$ticker]))
			return $this->qtySteps[$ticker];

		$params = [
			BybitParam::Category => $this->getBybitCategory($pair),
			BybitParam::Symbol => $pair->getExchangeTicker($this),

		];
		$response = $this->api->marketApi()->getInstrumentsInfo($params);
		$qtyStep = $response[BybitParam::List][0][BybitParam::LotSizeFilter][BybitParam::QtyStep] ?? '0.01';
		$this->qtySteps[$ticker] = $qtyStep;
		return $qtyStep;
	}

	/**
	 * @inheritDoc
	 *
	 * TODO: Fetch instrument info when initializing Market for better performance.
	 */
	public function getTickSize(IMarket $market): string {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

		// We already have it cached.
		if (isset($this->tickSizes[$ticker]))
			return $this->tickSizes[$ticker];

		$params = [
			BybitParam::Category => $this->getBybitCategory($pair),
			BybitParam::Symbol => $pair->getExchangeTicker($this),
		];
		$response = $this->api->marketApi()->getInstrumentsInfo($params);
		$tickSize = $response[BybitParam::List][0][BybitParam::PriceFilter][BybitParam::TickSize] ?? '0.0001';
		$this->tickSizes[$ticker] = $tickSize;
		return $tickSize;
	}

	/**
	 * @inheritDoc
	 */
	public function removeLimitOrders(IMarket $market): bool {
		$pair = $market->getPair();
		try {
			$this->api->tradeApi()->cancelAllOrders([
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
			]);

			return true;
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function setTakeProfit(IMarket $market, Money $expectedPrice): bool {
		$pair = $market->getPair();
		try {
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
				BybitParam::TPTriggerBy => BybitTPTriggerByEnum::LastPrice->value,
				BybitParam::TakeProfit => $expectedPrice->formatForOrder($this->getTickSize($market)),
			];

			// Hedge mode requires positionIdx to identify which position the TP belongs to.
			$position = $market->getCurrentPosition();
			if ($position) {
				$params[BybitParam::PositionIdx] = $this->getPositionIdxByDirection($position->getDirection());
			}

			$this->api->positionApi()->setTradingStop($params);
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to set TP for {$market->getTicker()}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function setStopLoss(IMarket $market, Money $expectedPrice): bool {
		$pair = $market->getPair();
		try {
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
				BybitParam::SLTriggerBy => BybitTPTriggerByEnum::LastPrice->value,
				BybitParam::StopLoss => $expectedPrice->formatForOrder($this->getTickSize($market)),
			];

			// Hedge mode requires positionIdx to identify which position the SL belongs to.
			$position = $market->getCurrentPosition();
			if ($position) {
				$params[BybitParam::PositionIdx] = $this->getPositionIdxByDirection($position->getDirection());
			}

			$this->api->positionApi()->setTradingStop($params);
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Failed to set SL for {$market->getTicker()}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function partialClose(IMarket $market, Money $volume, bool $isBreakevenLock = false, ?Money $closePrice = null): bool {
		$pair = $market->getPair();
		try {
			// Get the current position to determine direction.
			$position = $this->getCurrentFuturesPosition($market);
			if (!$position) {
				$this->logger->error("Cannot partial close: no position found for {$market->getTicker()}");
				return false;
			}

			// Reduce-only order: sell if long, buy if short.
			$closeSide = $position->getDirection()->isLong() ? 'Sell' : 'Buy';

			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
				BybitParam::Side => $closeSide,
				BybitParam::OrderType => OrderTypeEnum::MARKET->value,
				BybitParam::Qty => $volume->formatForOrder($this->getQtyStep($market)),
				BybitParam::IsReduceOnly => true,
				BybitParam::PositionIdx => $this->getPositionIdxByDirection($position->getDirection()),
			];

			$response = $this->api->tradeApi()->placeOrder($params);

			if (isset($response[BybitParam::OrderId])) {
				$this->logger->info("Partial close on {$market->getTicker()}: closed {$volume->format()}");
				return true;
			}

			$this->logger->error("Failed to partial close on {$market->getTicker()}: " . json_encode($response));
			return false;
		} catch (Throwable $e) {
			$this->logger->error("Failed to partial close on {$market->getTicker()}: " . $e->getMessage());
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
		try {
			// Reduce-only limit order: sell if long, buy if short.
			$closeSide = $direction->isLong() ? 'Sell' : 'Buy';

			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
				BybitParam::Side => $closeSide,
				BybitParam::OrderType => OrderTypeEnum::LIMIT->value,
				BybitParam::Qty => $volume->formatForOrder($this->getQtyStep($market)),
				BybitParam::Price => $price->formatForOrder($this->getTickSize($market)),
				BybitParam::IsReduceOnly => true,
				BybitParam::PositionIdx => $this->getPositionIdxByDirection($direction),
			];

			$response = $this->api->tradeApi()->placeOrder($params);

			if (isset($response[BybitParam::OrderId])) {
				$this->logger->info("Placed limit close on {$market->getTicker()}: {$volume->format()} @ {$price->format()}");
				return $response[BybitParam::OrderId];
			}

			$this->logger->error("Failed to place limit close on {$market->getTicker()}: " . json_encode($response));
			return false;
		} catch (Throwable $e) {
			$this->logger->error("Failed to place limit close on {$market->getTicker()}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getMarginMode(IMarket $market): ?MarginModeEnum {
		$pair = $market->getPair();
		$symbol = $pair->getExchangeTicker($this);

		// Return cached value if we already switched during this session.
		if (isset($this->marginModeCache[$symbol])) {
			return $this->marginModeCache[$symbol];
		}

		try {
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $symbol,
			];
			$response = $this->api->positionApi()->getPositionInfo($params);
			$positionList = $response[BybitParam::List] ?? [];
			foreach ($positionList as $positionInfo) {
				// tradeMode: 0 = cross, 1 = isolated
				$tradeMode = (int)($positionInfo[BybitParam::TradeMode] ?? 0);
				return $tradeMode === 1 ? MarginModeEnum::ISOLATED : MarginModeEnum::CROSS;
			}
			// Empty position list — symbol may not have been traded yet on this
			// account. Cannot determine margin mode; return null so that the
			// validation produces a warning instead of a hard error.
			$this->logger->warning("getMarginMode: empty position list for {$market->getTicker()}, cannot determine margin mode");
			return null;
		} catch (Throwable $e) {
			$this->logger->error("Failed to get margin mode for {$market->getTicker()}: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Switches margin mode for a symbol on Bybit via /v5/position/switch-isolated.
	 * The API requires buyLeverage and sellLeverage to be equal, so we read the
	 * current leverage from the position info first.
	 */
	public function switchMarginMode(IMarket $market, MarginModeEnum $mode): bool {
		$pair = $market->getPair();
		$symbol = $pair->getExchangeTicker($this);
		// Already switched during this session — skip the API call.
		if (isset($this->marginModeCache[$symbol]) && $this->marginModeCache[$symbol] === $mode) {
			return true;
		}

		// Strategy 1: per-symbol switch (classic accounts).
		try {
			$currentLeverage = $this->getLeverage($market) ?? 1.0;
			$leverageStr = (string)$currentLeverage;

			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $symbol,
				BybitParam::TradeMode => $mode->isIsolated() ? 1 : 0,
				BybitParam::BuyLeverage => $leverageStr,
				BybitParam::SellLeverage => $leverageStr,
			];

			$this->logger->info("Switching margin mode to {$mode->getLabel()} for $symbol (leverage=$leverageStr)");
			$this->api->positionApi()->switchCrossIsolatedMargin($params);
			$this->logger->info("Successfully switched margin mode to {$mode->getLabel()} for $symbol");
			$this->marginModeCache[$symbol] = $mode;
			return true;
		} catch (Throwable $e) {
			$this->logger->warning("Per-symbol margin switch failed for $symbol: " . $e->getMessage());
		}

		// Strategy 2: account-level switch (Unified Trading Account).
		// On UTA, /v5/position/switch-isolated is forbidden; use
		// /v5/account/set-margin-mode instead. This changes the margin
		// mode for the entire account, not just one symbol.
		try {
			$accountMode = $mode->isIsolated() ? 'ISOLATED_MARGIN' : 'REGULAR_MARGIN';
			$this->logger->info("Attempting account-level margin mode switch to $accountMode (UTA fallback)");
			$this->api->accountApi()->setMarginMode(['setMarginMode' => $accountMode]);
			$this->logger->info("Successfully switched account margin mode to $accountMode");
			$this->marginModeCache[$symbol] = $mode;
			return true;
		} catch (Throwable $e) {
			$this->logger->error("Account-level margin switch also failed: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getLeverage(IMarket $market): ?float {
		$pair = $market->getPair();
		try {
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
			];
			$response = $this->api->positionApi()->getPositionInfo($params);
			$positionList = $response[BybitParam::List] ?? [];
			foreach ($positionList as $positionInfo) {
				return (float)($positionInfo['leverage'] ?? 1.0);
			}
			// Empty position list — cannot determine leverage.
			$this->logger->warning("getLeverage: empty position list for {$market->getTicker()}, cannot determine leverage");
			return null;
		} catch (Throwable $e) {
			$this->logger->error("Failed to get leverage for {$market->getTicker()}: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getPositionMode(IMarket $market): ?PositionModeEnum {
		$pair = $market->getPair();
		try {
			$params = [
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
			];
			$response = $this->api->positionApi()->getPositionInfo($params);
			$positionList = $response[BybitParam::List] ?? [];
			if (empty($positionList)) {
				// Empty position list — cannot determine position mode.
				$this->logger->warning("getPositionMode: empty position list for {$market->getTicker()}, cannot determine position mode");
				return null;
			}
			// Bybit returns 2 entries (positionIdx 1 and 2) in hedge mode,
			// 1 entry (positionIdx 0) in one-way mode.
			if (count($positionList) >= 2) {
				return PositionModeEnum::HEDGE;
			}
			return PositionModeEnum::ONE_WAY;
		} catch (Throwable $e) {
			$this->logger->error("Failed to get position mode for {$market->getTicker()}: " . $e->getMessage());
			return null;
		}
	}

	private function getPositionIdxByDirection(PositionDirectionEnum $direction): int {
		return match ($direction) {
			PositionDirectionEnum::LONG => 1,
			PositionDirectionEnum::SHORT => 2,
		};
	}

	public function getTakerFee(MarketTypeEnum $marketType): float {
		return match ($marketType) {
			MarketTypeEnum::FUTURES => 0.00055, // 0.055%
			MarketTypeEnum::SPOT => 0.001,      // 0.1%
		};
	}

	public function getMakerFee(MarketTypeEnum $marketType): float {
		return match ($marketType) {
			MarketTypeEnum::FUTURES => 0.0002, // 0.02%
			MarketTypeEnum::SPOT => 0.001,     // 0.1%
		};
	}
}
