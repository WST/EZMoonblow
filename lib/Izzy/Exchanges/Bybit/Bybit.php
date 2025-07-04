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
			$this->logger->error("Failed to connect to exchange $this->exchangeName: " . $e->getMessage());
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
	 * Refresh total account balance information.
	 * NOTE: Earn API is not implemented in the SDK.
	 */
	public function updateBalance(): void {
		try {
			$params = ['accountType' => AccountType::UNIFIED];
			$info = $this->api->accountApi()->getWalletBalance($params);
			if (!isset($info['list'][0]['totalEquity'])) {
				$this->logger->error("Failed to get balance: invalid response format from Bybit");
				return;
			}
			$value = (float)$info['list'][0]['totalEquity'];
			$totalBalance = Money::from($value);
			$this->saveBalance($totalBalance);
		} catch (Exception $e) {
			$this->logger->error("Unexpected error while updating balance on $this->exchangeName: " . $e->getMessage());
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
				'category' => $this->getBybitCategory($pair),
				'symbol' => $ticker,
				'interval' => $this->timeframeToBybitInterval($pair->getTimeframe()),
				'limit' => $limit
			];
			
			if ($startTime !== null) $params['start'] = $startTime;
			if ($endTime !== null) $params['end'] = $endTime;

			$response = $this->api->marketApi()->getKline($params);
			
			if (empty($response['list'])) {
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
				$response['list']
			);

			// Sort candles by time (oldest to newest).
			usort($candles, fn($a, $b) => $a->getOpenTime() - $b->getOpenTime());

			return $candles;
		} catch (HttpException $exception) {
			$this->logger->error("Failed to get candles for $ticker on $this->exchangeName: " . $exception->getMessage());
			return [];
		} catch (Exception $e) {
			$this->logger->error("Unexpected error while getting candles for $ticker on $this->exchangeName: " . $e->getMessage());
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
			throw new InvalidArgumentException("Unknown pair type for Bybit: " . $pair->getMarketType()->toString());
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
				'category' => $this->getBybitCategory($pair),
				'symbol' => $ticker
			];

			$response = $this->api->marketApi()->getTickers($params);
			
			if (empty($response['list'])) {
				$this->logger->error("Failed to get current price for $ticker: empty response");
				return null;
			}

			// Find the ticker in the response
			foreach ($response['list'] as $tickerData) {
				if ($tickerData['symbol'] === $ticker) {
					return Money::from($tickerData['lastPrice']);
				}
			}

			$this->logger->error("Ticker $ticker not found in response");
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

				// Save the “position” to the database.
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
			'category' => $this->getBybitCategory($pair),
			'symbol' => $ticker,
			'side' => $direction->getBuySell(),
			'orderType' => 'Market',
		];

		if ($market->isSpot()) {
			// Adding to the params for the API call.
			$params['qty'] = $amount->formatForOrder($this->getTickSize($market));

			// NOTE: we cannot assign TP here.
		}

		if ($market->isFutures()) {
			// For spot, the amount should be in the quote currency, for futures in the base currency.
			$entryVolume = $market->calculateQuantity(Money::from($amount), $currentPrice);

			// Amount should be adjusted using QtyStep.
			$properVolume = $entryVolume->formatForOrder($this->getQtyStep($market));
			
			// Adding to the params for the API call.
			$params['qty'] = $properVolume;
			
			// If we have a TP defined.
			if ($takeProfitPercent) {
				$takeProfitPrice = $currentPrice->modifyByPercentWithDirection($takeProfitPercent, $direction);
				$params["takeProfit"] = $takeProfitPrice->formatForOrder($this->getTickSize($market));
			}

			$params['positionIdx'] = $this->getPositionIdxByDirection($direction);
			if ($direction->isShort()) {
				$params['isReduceOnly'] = false;
			}
		}
		
		try {
			// Make an API call.
			$response = $this->api->tradeApi()->placeOrder($params);
			
			if (!isset($response['orderId'])) {
				$this->logger->error("Failed to open long position on $market: " . json_encode($response));
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
				$response['orderId']
			);
			$position->setAverageEntryPrice($currentPrice);
			$position->setExpectedProfitPercent($takeProfitPercent);
			
			// If there is a TP, set it.
			if ($takeProfitPrice) {
				$position->setTakeProfitPrice($takeProfitPrice);
				$this->setTakeProfit($market, $takeProfitPrice);
			}
			
			// Save the “position” to the database.
			$position->save();

			// Success.
			return true;
		} catch (Exception $e) {
			// Inform the user.
			$this->logger->error("Failed to open long position on $market: " . $e->getMessage());
			
			// Failure.
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function openShort(IMarket $market, Money $amount, ?Money $price = null, ?float $takeProfitPercent = null): bool {
		// TODO
		return false;
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
	 * Bybit uses tickers like “BTCUSDT” for pairs.
	 * @param IPair $pair
	 * @return string
	 */
	public function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency() . $pair->getQuoteCurrency();
	}
	
	public function getOrderById(IMarket $market, string $orderIdOnExchange): Order|false {
		$pair = $market->getPair();
		$params = [
			'category' => $this->getBybitCategory($pair),
			'symbol' => $market->getPair()->getExchangeTicker($this),
			'orderId' => $orderIdOnExchange,
		];
		$response = $this->api->tradeApi()->getOpenOrders($params);

		// If there is no order list in the response, there was probably no such order.
		if (!isset($response['list'])) return false;

		// But if it’s empty, we also think that there was no such order.
		if (empty($response['list'])) return false;
		
		// Order info is an array. Let’s build an object for convenience.
		$orderInfo = $response['list'][0];
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
		$order->setOrderType(OrderTypeEnum::from($orderInfo['orderType']));
		$order->setStatus($this->getOrderStatusFromInfoString($orderInfo['orderStatus']));
		$order->setIdOnExchange($orderInfo['orderId']);
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
		$params = ['accountType' => 'UNIFIED', 'coin' => $coin];
		$response = $this->api->assetApi()->getSingleCoinBalance($params);
		$balanceInfo = $response['balance'];
		return Money::from($balanceInfo['walletBalance'], $coin);
	}

	public function getCurrentFuturesPosition(IMarket $market): IPositionOnExchange|false {
		$pair = $market->getPair();
		$marketType = $market->getMarketType();
		if (!$marketType->isFutures()) {
			$this->logger->error("Trying to get a futures position on a spot market: $market");
			return false;
		}
		
		$params = [
			'category' => $this->getBybitCategory($pair),
			'symbol' => $pair->getExchangeTicker($this),
		];
		
		$response = $this->api->positionApi()->getPositionInfo($params);
		$positionList = $response['list'];
		
		/*
		 * Bybit always returns 2 positions in 1 list for two-way mode.
		 * Long has positionIdx = 1, Short has positionIdx = 2.
		 */
		foreach ($positionList as $positionInfo) {
			$positionIdx = $positionInfo['positionIdx'];
			
			// Skip empty positions.
			if (Money::from($positionInfo['size'], $pair->getBaseCurrency())?->isZero()) continue;
			
			// It’s a Long position.
			if ($positionIdx == 1) {
				return PositionOnBybit::create($market, $positionInfo);
			}
			
			// It’s a Short position.
			if ($positionIdx == 2) {
				return PositionOnBybit::create($market, $positionInfo);
			}
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
				'category' => $this->getBybitCategory($pair),
				'symbol' => $ticker,
				'side' => $direction->getBuySell(),
				'orderType' => 'Limit',
				'qty' => $amount->formatForOrder($this->getQtyStep($market)),
				'price' => $price->formatForOrder($this->getTickSize($market)),
				'positionIdx' => $this->getPositionIdxByDirection($direction),
			];

			// If we have a TP defined.
			if ($takeProfitPercent) {
				$takeProfitPrice = $price->modifyByPercentWithDirection($takeProfitPercent, $direction);
				$params["takeProfit"] = $takeProfitPrice->formatForOrder($this->getTickSize($market));
			}

			// Make an API call.
			$response = $this->api->tradeApi()->placeOrder($params);

			if (isset($response['orderId'])) {
				return $response['orderId'];
			} else {
				$this->logger->error("Failed to place a limit order on $market: " . json_encode($response));
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to place a limit order on $market: " . $e->getMessage());
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
		if (isset($this->qtySteps[$ticker])) return $this->qtySteps[$ticker];
		
		$params = [
			'category' => $this->getBybitCategory($pair),
			'symbol' => $pair->getExchangeTicker($this),
			
		];
		$response = $this->api->marketApi()->getInstrumentsInfo($params);
		$qtyStep = $response['list'][0]['lotSizeFilter']['qtyStep'] ?? '0.01';
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
		if (isset($this->tickSizes[$ticker])) return $this->tickSizes[$ticker];

		$params = [
			'category' => $this->getBybitCategory($pair),
			'symbol' => $pair->getExchangeTicker($this),
		];
		$response = $this->api->marketApi()->getInstrumentsInfo($params);
		$tickSize = $response['list'][0]['priceFilter']['tickSize'] ?? '0.0001';
		$this->tickSizes[$ticker] = $tickSize;
		return $tickSize;
	}

	public function removeLimitOrders(IMarket $market): bool {
		$pair = $market->getPair();
		try {
			$this->api->tradeApi()->cancelAllOrders([
				'category' => $this->getBybitCategory($pair),
				'symbol' => $pair->getExchangeTicker($this),
			]);

			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public function setTakeProfit(IMarket $market, Money $expectedPrice): bool {
		$pair = $market->getPair();
		try {
			$this->api->positionApi()->setTradingStop([
				'category' => $this->getBybitCategory($pair),
				'symbol' => $pair->getExchangeTicker($this),
				'tpTriggerBy' => 'LastPrice',
				'takeProfit' => $expectedPrice->formatForOrder($this->getTickSize($market)),
			]);
			return true;
		} catch (\Throwable $e) {
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
