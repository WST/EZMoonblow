<?php

namespace Izzy\Exchanges\Bybit;

use ByBit\SDK\ByBitApi;
use ByBit\SDK\Enums\AccountType;
use ByBit\SDK\Enums\OrderStatus;
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
use Izzy\Financial\Position;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPosition;

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
			
			// Test connection by requesting candles for BTCUSDT.
			$testResponse = $this->api->marketApi()->getKline([
				'category' => 'spot',
				'symbol' => 'BTCUSDT',
				'interval' => '1',
				'limit' => 1
			]);
			
			if (!isset($testResponse['list'])) {
				$this->logger->error("Failed to get test data from Bybit, connection not established.");
				return false;
			}
			
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
	 * Open a long position.
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount to invest.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openLong(IMarket $market, Money $amount): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$params = [
				'category' => 'spot',
				'symbol' => $pair->getExchangeTicker($this),
				'side' => 'Buy',
				'orderType' => 'Market',
				'qty' => $amount->formatForOrder(), // qty is provided in USDT
			];
			
			var_dump($params); return false;

			// Make an API call.
			$response = $this->api->tradeApi()->placeOrder($params);
			
			if (isset($response['orderId'])) {
				$this->logger->warning("Successfully opened long position on Bybit for $market: $amount");
				
				// Save position to database
				$currentPrice = $this->getCurrentPrice($market);
				$entryPrice = Money::from($currentPrice);
				$positionStatus = PositionStatusEnum::OPEN;
				
				// Now get the order by it’s ID to see the exact amount.
				$order = $this->getOrderById($market, $response['orderId']);
				$orderAmountInBaseCurrency = $order->getVolume();
				
				// Create and save the position. For the spot market, positions are emulated.
				$position = Position::create(
					$market,
					$amount,
					PositionDirectionEnum::LONG,
					$entryPrice,
					$currentPrice,
					$positionStatus,
					$response['orderId']
				);
				$position->save();
				
				return true;
			} else {
				$this->logger->error("Failed to open long position on $market: " . json_encode($response));
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to open long position on $market: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function openShort(IMarket $market, Money $amount, ?float $price = null): bool {
		// TODO
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$params = [
				'category' => $market->getMarketType()->isSpot() ? 'spot' : 'linear',
				'symbol' => $ticker,
				'side' => 'Buy',
				'orderType' => 'Market',
				'qty' => $amount->formatForOrder(),
			];

			$response = $this->api->tradeApi()->placeOrder($params);
			
			if (isset($response['result']['orderId'])) {
				$this->logger->info("Successfully executed DCA buy for $ticker: $amount");
				return true;
			} else {
				$this->logger->error("Failed to execute DCA buy for $ticker: " . json_encode($response));
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to execute DCA buy for $ticker: " . $e->getMessage());
			return false;
		}
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
		$params = [
			'category' => 'spot',
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

	public function getCurrentFuturesPosition(IMarket $market): IPosition|false {
		$pair = $market->getPair();
		$marketType = $market->getMarketType();
		if (!$marketType->isFutures()) {
			$this->logger->error("Trying to get a futures position on a spot market: $market");
			return false;
		}
		
		$params = [
			'category' => 'linear',
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
			$positionSize = Money::from($positionInfo['size'], $pair->getBaseCurrency());
			$avgPrice = Money::from($positionInfo['avgPrice'], $pair->getQuoteCurrency());
			$currentPrice = Money::from($positionInfo['markPrice'], $pair->getQuoteCurrency());
			
			// Skip empty positions.
			if ($positionSize->isZero()) continue;
			
			// It’s a Long position.
			if ($positionIdx == 1) {
				return Position::create(
					$market,
					$positionSize,
					PositionDirectionEnum::LONG,
					$avgPrice, // NOTE: this is the average price, not the “entry” price!
					$currentPrice,
					PositionStatusEnum::OPEN,
					''
				);
			}
			
			// It’s a Short position.
			if ($positionIdx == 2) {
				return Position::create(
					$market,
					$positionSize,
					PositionDirectionEnum::SHORT,
					$avgPrice, // NOTE: this is the average price, not the “entry” price!
					$currentPrice,
					PositionStatusEnum::OPEN,
					''
				);
			}
		}
		
		return false;
	}

	public function placeLimitOrder(IMarket $market, Money $amount, Money $price, string $side): string|false {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$params = [
				'category' => $market->getMarketType()->isSpot() ? 'spot' : 'linear',
				'symbol' => $pair->getExchangeTicker($this),
				'side' => $side,
				'orderType' => 'Limit',
				'qty' => $amount->formatForOrder($this->getQtyStep($market)),
				'price' => $price->formatForOrder('0.0001'),
				'positionIdx' => ($side == 'Buy') ? 1 : 2,
			];

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
	
	public function getQtyStep(IMarket $market): string {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		
		// We already have it cached.
		if (isset($this->qtySteps[$ticker])) return $this->qtySteps[$ticker];
		
		$params = [
			'category' => $market->getMarketType()->isSpot() ? 'spot' : 'linear',
			'symbol' => $pair->getExchangeTicker($this),
			
		];
		$response = $this->api->marketApi()->getInstrumentsInfo($params);
		$qtyStep = $response['list'][0]['lotSizeFilter']['qtyStep'] ?? '0.01';
		$this->qtySteps[$ticker] = $qtyStep;
		return $qtyStep;
	}
}
