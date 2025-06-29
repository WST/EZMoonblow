<?php

namespace Izzy\Exchanges;

use ByBit\SDK\ByBitApi;
use ByBit\SDK\Enums\AccountType;
use ByBit\SDK\Exceptions\HttpException;
use Exception;
use InvalidArgumentException;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Candle;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Financial\Position;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IPair;

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
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openLong(IMarket $market, Money $amount, ?float $price = null): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$category = 'spot';
			$side = 'Buy';
			$orderType = $price ? 'Limit' : 'Market';

			// Spot Market Buy order, qty is quote currency
			// {"category":"spot","symbol":"BTCUSDT","side":"Buy","orderType":"Market","qty":"200","timeInForce":"IOC","orderLinkId":"spot-test-04","isLeverage":0,"orderFilter":"Order"}
			
			$params = [
				'category' => $category,
				'symbol' => $pair->getExchangeTicker($this),
				'side' => $side,
				'orderType' => $orderType,
				'qty' => $market->calculateQuantity($amount, $price)->formatForOrder(),
			];

			if ($price) {
				$params['price'] = (string)$price;
			}
			
			var_dump($params);

			$response = $this->api->tradeApi()->placeOrder($params);
			
			if (isset($response['result']['orderId'])) {
				$this->logger->warning("Successfully opened long position on Bybit for $market: $amount");
				
				// Save position to database
				$currentPrice = $this->getCurrentPrice($market);
				$entryPrice = Money::from($currentPrice);
				$positionStatus = ($orderType == 'Market') ? PositionStatusEnum::OPEN : PositionStatusEnum::PENDING;
				
				// Create and save the position. For the spot market, positions are emulated.
				$position = Position::create(
					$market,
					$amount,
					PositionDirectionEnum::LONG,
					$entryPrice,
					$currentPrice,
					$positionStatus,
					$response['result']['orderId']
				);
				$position->save();
				
				return true;
			} else {
				$this->logger->error("Failed to open long position for {$pair->getTicker()}: " . json_encode($response));
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to open long position for {$pair->getTicker()}: " . $e->getMessage());
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
			// Safety check: limit DCA amount to $50
			if ($amount->getAmount() > 50.0) {
				$this->logger->warning("DCA amount $amount exceeds $50 limit, reducing to $50");
				$amount->setAmount(50.0);
			}

			$params = [
				'category' => 'spot',
				'symbol' => $ticker,
				'side' => 'Buy',
				'orderType' => 'Market',
				'qty' => $this->calculateQuantity($market, $amount, null)->formatForOrder(),
			];

			$response = $this->api->orderApi()->submitOrder($params);
			
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
}
