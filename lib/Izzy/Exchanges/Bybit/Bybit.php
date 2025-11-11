<?php

namespace Izzy\Exchanges\Bybit;

use ByBit\SDK\ByBitApi;
use ByBit\SDK\Enums\AccountType;
use ByBit\SDK\Exceptions\HttpException;
use Exception;
use InvalidArgumentException;
use Izzy\Enums\OrderStatusEnum;
use Izzy\Enums\OrderTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
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
class Bybit extends AbstractExchangeDriver {
	/** @var string Exchange name identifier. */
	protected string $exchangeName = 'Bybit';

	/** @var ByBitApi API instance for communication with the exchange. */
	protected ByBitApi $api;

	/**
	 * List of the markets being traded on / monitored.
	 * @var IMarket[]
	 */
	protected array $markets = [];

	protected array $qtySteps = [];

	protected array $tickSizes = [];

	/**
	 * Connect to the Bybit exchange using API credentials.
	 *
	 * @return bool True if connection successful, false otherwise.
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
	 * Disconnect from the exchange.
	 * TODO: Implement disconnect() method.
	 */
	public function disconnect(): void {
		// TODO: Implement disconnect() method.
	}

	/**
	 * Check if an exception represents an API key authentication error.
	 * @param Exception $e Exception to check.
	 * @return bool True if the exception is related to invalid API credentials, false otherwise.
	 */
	private function isApiKeyError(Exception $e): bool {
		$errorMessage = $e->getMessage();
		$lowerErrorMessage = strtolower($errorMessage);

		// Check for explicit "API key is invalid" or similar messages.
		if (stripos($errorMessage, 'API key') !== false &&
		    (stripos($errorMessage, 'invalid') !== false || stripos($errorMessage, 'is invalid') !== false)) {
			return true;
		}

		// Check for other authentication-related errors.
		if (stripos($lowerErrorMessage, 'authentication') !== false ||
		    stripos($lowerErrorMessage, 'unauthorized') !== false ||
		    stripos($lowerErrorMessage, 'api key invalid') !== false) {
			return true;
		}

		return false;
	}

	/**
	 * Refresh total account balance information.
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
				fn($item) => new Candle(
					(int)($item[0] / 1000), // timestamp (convert from milliseconds to seconds).
					(float)$item[1], // open.
					(float)$item[2], // high.
					(float)$item[3], // low.
					(float)$item[4], // close.
					(float)$item[5]  // volume.
				),
				$response[BybitParam::List]
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
				$market,
				$amount,
				$price,
				$direction,
				$takeProfitPercent
			);
			if ($orderIdOnExchange) {
				/**
				 * Create and save the position. For the spot market, positions are emulated.
				 * So, we use a StoredPosition instead of PositionOnBybit here.
				 */
				$position = StoredPosition::create(
					$market,
					$amount,
					$direction,
					$currentPrice,
					$currentPrice,
					PositionStatusEnum::OPEN,
					$orderIdOnExchange
				);

				// If we have a TP defined.
				if ($takeProfitPercent) {
					$takeProfitPrice = $price->modifyByPercentWithDirection($takeProfitPercent, $direction);
					$position->setExpectedProfitPercent($takeProfitPercent);
					$position->setTakeProfitPrice($takeProfitPrice);
					$this->setTakeProfit($market, $takeProfitPrice);
				}

				$position->setAverageEntryPrice($currentPrice);

				// Save the "position" to the database.
				$position->save();

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
			BybitParam::OrderType => 'Market',
		];

		if ($market->isSpot()) {
			// Adding to the params for the API call.
			$params[BybitParam::Qty] = $amount->formatForOrder($this->getTickSize($market));

			// NOTE: we cannot assign TP here.
		}

		if ($market->isFutures()) {
			// For spot, the amount should be in the quote currency, for futures in the base currency.
			$entryVolume = $market->calculateQuantity(Money::from($amount), $currentPrice);

			// Amount should be adjusted using QtyStep.
			$properVolume = $entryVolume->formatForOrder($this->getQtyStep($market));

			// Adding to the params for the API call.
			$params[BybitParam::Qty] = $properVolume;

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

		try {
			// Make an API call.
			$response = $this->api->tradeApi()->placeOrder($params);

			if (!isset($response[BybitParam::OrderId])) {
				$this->logger->error("Failed to open long position on $market: ".json_encode($response));
				return false;
			}

			// Inform the user.
			$this->logger->warning("Successfully opened long position on Bybit for $market: $properAmount");

			/**
			 * Create and save the position. For the spot market, positions are emulated.
			 * So, we use a StoredPosition instead of PositionOnBybit here.
			 */
			$position = StoredPosition::create(
				$market,
				$amount,
				PositionDirectionEnum::LONG,
				$currentPrice,
				$currentPrice,
				PositionStatusEnum::OPEN,
				$response[BybitParam::OrderId]
			);
			$position->setAverageEntryPrice($currentPrice);

			// If there is a TP, set it.
			if ($takeProfitPercent) {
				$position->setExpectedProfitPercent($takeProfitPercent);
				$takeProfitPrice = $currentPrice->modifyByPercentWithDirection($takeProfitPercent, $direction);
				$position->setTakeProfitPrice($takeProfitPrice);
				$this->setTakeProfit($market, $takeProfitPrice);
			}

			// Save the "position" to the database.
			$position->save();

			// Success.
			return true;
		} catch (Exception $e) {
			// Inform the user.
			$this->logger->error("Failed to open long position on $market: ".$e->getMessage());

			// Failure.
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);

	}

	/**
	 * @inheritDoc
	 */
	public function sellAdditional(IMarket $market, Money $amount): bool {
		// TODO
		return false;
	}

	/**
	 * Bybit uses tickers like "BTCUSDT" for pairs.
	 * @param IPair $pair
	 * @return string
	 */
	public function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency().$pair->getQuoteCurrency();
	}

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
	 * @param IMarket $market
	 * @param string $orderIdOnExchange
	 * @return bool
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
		};
	}

	public function getSpotBalanceByCurrency(string $coin): Money {
		$params = [BybitParam::AccountType => 'UNIFIED', BybitParam::Coin => $coin];
		$response = $this->api->assetApi()->getSingleCoinBalance($params);
		$balanceInfo = $response[BybitParam::Balance];
		return Money::from($balanceInfo[BybitParam::WalletBalance], $coin);
	}

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
	 * NOTE: We cannot detect TP order Id at this point.
	 * @param IMarket $market
	 * @param Money $amount
	 * @param Money $price
	 * @param PositionDirectionEnum $direction
	 * @param float|null $takeProfitPercent
	 * @return string|false
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
				BybitParam::OrderType => 'Limit',
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
	 * TODO: fetch instrument info when initializing Market.
	 * @param IMarket $market
	 * @return string
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
	 * TODO: fetch instrument info when initializing Market.
	 * @param IMarket $market
	 * @return string
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

	public function setTakeProfit(IMarket $market, Money $expectedPrice): bool {
		$pair = $market->getPair();
		try {
			$this->api->positionApi()->setTradingStop([
				BybitParam::Category => $this->getBybitCategory($pair),
				BybitParam::Symbol => $pair->getExchangeTicker($this),
				BybitParam::TPTriggerBy => BybitTPTriggerByEnum::LastPrice->value,
				BybitParam::TakeProfit => $expectedPrice->formatForOrder($this->getTickSize($market)),
			]);
			return true;
		} catch (Throwable $e) {
			return false;
		}
	}

	private function getPositionIdxByDirection(PositionDirectionEnum $direction): int {
		return match ($direction) {
			PositionDirectionEnum::LONG => 1,
			PositionDirectionEnum::SHORT => 2,
		};
	}
}
