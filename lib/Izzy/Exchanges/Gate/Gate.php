<?php

namespace Izzy\Exchanges\Gate;

use Exception;
use GateApi\Api\FuturesApi;
use GateApi\Api\SpotApi;
use GateApi\Api\WalletApi;
use GateApi\ApiException;
use GateApi\Configuration;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\AbstractExchangeDriver;
use Izzy\Financial\Candle;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IStoredPosition;
use Izzy\Interfaces\IPositionOnExchange;

/**
 * Driver for working with Gate exchange.
 * Provides integration with Gate.io cryptocurrency exchange API.
 */
class Gate extends AbstractExchangeDriver
{
	/** @var string Exchange name identifier. */
	protected string $exchangeName = "Gate";

	/** @var WalletApi Wallet API instance for balance operations. */
	private WalletApi $walletApi;

	/** @var SpotApi Spot API instance for trading operations. */
	private SpotApi $spotApi;
	
	/** @var FuturesApi Futures API instance for futures trading operations. */
	private FuturesApi $futuresApi;
	

	
	/** @var \GuzzleHttp\Client HTTP client for direct API calls. */
	private \GuzzleHttp\Client $httpClient;

	/**
	 * Refresh account balance information from Gate exchange.
	 * Retrieves total balance in USDT currency.
	 */
	protected function refreshAccountBalance(): void {
		try {
			$info = $this->walletApi->getTotalBalance(['currency' => 'USDT']);
			$value = new Money($info->getTotal()->getAmount());
			$this->saveBalance($value);
		} catch (ApiException $exception) {
			$this->logger->error("Failed to update wallet balance on $this->exchangeName: " . $exception->getMessage());
		}
	}

	/**
	 * Connect to the Gate exchange using API credentials.
	 * Initializes Wallet API and Spot API instances.
	 * 
	 * @return bool True if connection successful, false otherwise.
	 */
	public function connect(): bool {
		$key = $this->config->getKey();
		$secret = $this->config->getSecret();
		
		// Create configuration with proper authentication
		$config = Configuration::getDefaultConfiguration()
			->setKey($key)
			->setSecret($secret)
			->setHost('https://api.gateio.ws/api/v4'); // Set the correct host

		// Create Wallet API instance.
		$this->walletApi = new WalletApi(null, $config);

		// Create Spot API instance.
		$this->spotApi = new SpotApi(null, $config);
		
		// Create Futures API instance for private operations.
		$this->futuresApi = new FuturesApi(null, $config);
		
		// Create HTTP client for direct API calls
		$this->httpClient = new \GuzzleHttp\Client([
			'base_uri' => 'https://api.gateio.ws/api/v4/',
			'timeout' => 30,
		]);

		return true;
	}

	/**
	 * Disconnect from the exchange.
	 * TODO: Implement disconnect() method.
	 */
	public function disconnect(): void {
		// TODO: Implement disconnect() method.
	}

	/**
	 * Convert internal timeframe to Gate interval format.
	 * Maps internal timeframe values to Gate API interval strings.
	 * 
	 * @param TimeFrameEnum $timeframe Internal timeframe enum.
	 * @return string|null Gate interval string or null if not supported.
	 */
	private function timeframeToGateInterval(TimeFrameEnum $timeframe): ?string {
		return match ($timeframe->value) {
			'1M' => '30d',
			default => $timeframe->value,
		};
	}

	/**
	 * Get candles for the specified trading pair and timeframe from Gate.io.
	 * Retrieves historical candlestick data and converts it to Candle objects.
	 *
	 * @param IPair $pair
	 * @param int $limit
	 * @param int|null $startTime
	 * @param int|null $endTime
	 * @return Candle[] Array of candle objects sorted by time.
	 */
	public function getCandles(IPair $pair, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array {
		$ticker = $pair->getExchangeTicker($this);
		try {
			$timeframe = $pair->getTimeframe();
			$gateInterval = $this->timeframeToGateInterval($timeframe);
			
			if (!$gateInterval) {
				$this->logger->error("Unknown timeframe $timeframe->value for Gate.");
				return [];
			}
			
			if ($pair->isSpot()) {
				$params = [
					'currency_pair' => $ticker,
					'interval' => $gateInterval,
					'limit' => $limit,
				];
				$response = $this->spotApi->listCandlesticks($params);
			} else {
				// For futures, use direct HTTP call to avoid authentication issues
				try {
					$response = $this->httpClient->get('futures/usdt/candlesticks', [
						'query' => [
							'contract' => $ticker,
							'interval' => $gateInterval,
							'limit' => $limit,
						]
					]);
					
					$responseData = json_decode($response->getBody()->getContents(), true);
					if (!is_array($responseData)) {
						$this->logger->error("Invalid response format for futures candles: " . json_last_error_msg());
						return [];
					}
					
					// Convert Gate API format to our expected format
					$response = array_map(function($candle) {
						return [
							$candle['t'], // timestamp
							$candle['v'], // volume
							$candle['c'], // close
							$candle['h'], // high
							$candle['l'], // low
							$candle['o'], // open
						];
					}, $responseData);
				} catch (\Exception $e) {
					$this->logger->error("Failed to get futures candles via HTTP: " . $e->getMessage());
					return [];
				}
			}
			
			if (empty($response)) {
				return [];
			}
			
			$candles = array_map(
				fn($item) => new Candle(
					(int)$item[0], // timestamp.
					(float)$item[5], // open.
					(float)$item[3], // high.
					(float)$item[4], // low.
					(float)$item[2], // close.
					(float)$item[1]  // volume.
				),
				$response
			);

			// Sort candles by time (oldest to newest).
			usort($candles, fn($a, $b) => $a->getOpenTime() - $b->getOpenTime());

			return $candles;
		} catch (ApiException $exception) {
			$this->logger->error("Failed to get candles for $ticker on $this->exchangeName: " . $exception->getMessage());
			return [];
		}
	}
	
	/**
	 * Update balance information.
	 * Delegates to refreshAccountBalance method.
	 */
	public function updateBalance(): void {
		$this->refreshAccountBalance();
	}

	/**
	 * Update the exchange state.
	 * @return int time to sleep before the next update in seconds.
	 */
	public function update(): int {
		$this->logger->info("Updating total balance information for {$this->getName()}");
		$this->updateBalance();
		
		// Updating the lists of pairs.
		$this->logger->info("Updating the list of pairs for {$this->getName()}");
		$this->updatePairs();
		
		// Update markets.
		$this->logger->info("Updating market data for {$this->getName()}");
		$this->updateMarkets();
		
		// Update charts for all markets.
		$this->logger->info("Updating charts for all markets on {$this->getName()}");
		$this->updateCharts();

		// Default sleep time of 60 seconds.
		return 60;
	}

	/**
	 * Get market instance for a trading pair.
	 *
	 * @param IPair $pair Trading pair.
	 * @return IMarket|null Market instance or null if not found.
	 */
	public function createMarket(IPair $pair): ?IMarket {
		$candlesData = $this->getCandles($pair);
		if (empty($candlesData)) {
			return null;
		}

		$market = new Market($this, $pair);
		$market->setCandles($candlesData);
		$market->initializeStrategy();
		$market->initializeIndicators();
		return $market;
	}

	/**
	 * Calculate quantity based on amount and price for Gate.io orders.
	 * 
	 * @param IMarket $market
	 * @param Money $amount
	 * @param Money|null $price
	 * @return string
	 */
	private function calculateQuantity(IMarket $market, Money $amount, ?Money $price): string {
		if ($price) {
			// For limit orders, calculate quantity as amount / price
			return (string)($amount->getAmount() / $price->getAmount());
		} else {
			// For market orders, use amount directly
			return (string)$amount->getAmount();
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPrice(IMarket $market): ?Money {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			if ($pair->isSpot()) {
				$params = ['currency_pair' => $ticker];
				$response = $this->spotApi->listTickers($params);
				if (!empty($response)) {
					// listTickers returns an array, we need the first item
					$tickerData = $response[0] ?? null;
					if ($tickerData && method_exists($tickerData, 'getLast')) {
						return new Money($tickerData->getLast());
					}
				}
			} else {
				// For futures, use direct HTTP call to avoid authentication issues
				try {
					$response = $this->httpClient->get('futures/usdt/tickers', [
						'query' => [
							'contract' => $ticker
						]
					]);
					
					$responseData = json_decode($response->getBody()->getContents(), true);
					if (is_array($responseData) && !empty($responseData)) {
						$tickerData = $responseData[0] ?? null;
						if ($tickerData && isset($tickerData['last'])) {
							return new Money($tickerData['last']);
						}
					}
				} catch (\Exception $e) {
					$this->logger->error("Failed to get futures ticker via HTTP: " . $e->getMessage());
				}
			}

			$this->logger->error("Failed to get current price for $ticker: invalid response");
			return null;
		} catch (Exception $e) {
			$this->logger->error("Failed to get current price for $ticker: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function openLong(IMarket $market, Money $amount, ?Money $price = null, ?float $takeProfitPercent = null): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		
	}

	/**
	 * @inheritDoc
	 */
	public function openShort(IMarket $market, Money $amount, ?Money $price = null, ?float $takeProfitPercent = null): bool {
		$this->logger->warning("Short positions not supported on Gate.io for $ticker");
		return false;
	}

	/**
	 * Place a market order to buy additional volume (DCA).
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount to buy.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			// Safety check: limit DCA amount to $50
			if ($amount->getAmount() > 50.0) {
				$this->logger->warning("DCA amount $amount exceeds $50 limit, reducing to $50");
				$amount = new Money(50.0, $amount->getCurrency());
			}
			
			$params = [
				'currency_pair' => $ticker,
				'side' => 'buy',
				'amount' => $this->calculateQuantity($market, $amount, null),
				'type' => 'market'
			];

			$response = $this->spotApi->createOrder($params);
			
			if ($response && $response->getId()) {
				$this->logger->info("Successfully executed DCA buy for $ticker: $amount");
				return true;
			} else {
				$this->logger->error("Failed to execute DCA buy for $ticker: invalid response");
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
	 * Gate uses tickers like "BTC_USDT" for pairs.
	 * @param IPair $pair
	 * @return string
	 */
	public function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency() . '_' . $pair->getQuoteCurrency();
	}

	public function getSpotBalanceByCurrency(string $coin): Money {
		return Money::from(0.00, $coin);
	}

	public function getCurrentFuturesPosition(IMarket $market): IPositionOnExchange|false {
		$pair = $market->getPair();
		$marketType = $market->getMarketType();
		if (!$marketType->isFutures()) {
			$this->logger->error("Trying to get a futures position on a spot market: $market");
			return false;
		}
		
		try {
			$contract = $pair->getExchangeTicker($this);
			$settle = 'USDT'; // Gate.io uses USDT as settle currency for futures
			
			$response = $this->futuresApi->getPosition($settle, $contract);
			
			// Check if position has any size (not empty)
			if ($response->getSize() != 0) {
				// Convert Gate API Position object to array for our PositionOnGate class
				$positionInfo = [
					'size' => $response->getSize(),
					'entry_price' => $response->getEntryPrice(),
					'mark_price' => $response->getMarkPrice(),
					'unrealised_pnl' => $response->getUnrealisedPnl(),
				];
				
				return \Izzy\Exchanges\Gate\PositionOnGate::create($market, $positionInfo);
			}
			
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to get futures position for $contract on $this->exchangeName: " . $e->getMessage());
			return false;
		}
	}

	public function placeLimitOrder(IMarket $market, Money $amount, Money $price, string $side, ?float $takeProfitPercent = null): string|false {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$params = [
				'currency_pair' => $ticker,
				'side' => $side,
				'amount' => $this->calculateQuantity($market, $amount, $price),
				'price' => (string)$price->getAmount(),
				'type' => 'limit'
			];

			$response = $this->spotApi->createOrder($params);
			
			if ($response && $response->getId()) {
				$this->logger->info("Successfully placed limit order for $ticker: $amount at $price");
				return $response->getId();
			} else {
				$this->logger->error("Failed to place limit order for $ticker: invalid response");
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to place limit order for $ticker: " . $e->getMessage());
			return false;
		}
	}

	public function removeLimitOrders(IMarket $market): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$this->spotApi->cancelOrders([
				'currency_pair' => $ticker
			]);
			$this->logger->info("Successfully removed limit orders for $ticker");
			return true;
		} catch (Exception $e) {
			$this->logger->error("Failed to remove limit orders for $ticker: " . $e->getMessage());
			return false;
		}
	}

	public function setTakeProfit(IMarket $market, Money $expectedPrice): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			// Gate.io doesn't support take profit orders in the basic API
			// This is a placeholder implementation
			$this->logger->warning("Take profit orders not supported on Gate.io for $ticker");
			return false;
		} catch (Exception $e) {
			$this->logger->error("Failed to set take profit for $ticker: " . $e->getMessage());
			return false;
		}
	}
}
