<?php

namespace Izzy\Exchanges;

use GateApi\Api\SpotApi;
use GateApi\Api\WalletApi;
use GateApi\ApiException;
use GateApi\Configuration;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Candle;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;

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

	/**
	 * Refresh account balance information from Gate exchange.
	 * Retrieves total balance in USDT currency.
	 */
	protected function refreshAccountBalance(): void {
		try {
			$info = $this->walletApi->getTotalBalance(['currency' => 'USDT']);
			$value = new Money($info->getTotal()->getAmount());
			$this->logger->info("Balance on {$this->exchangeName}: {$value} USDT");
			$this->database->setExchangeBalance($this->exchangeName, $value);
		} catch (ApiException $exception) {
			$this->logger->error("Failed to update wallet balance on {$this->exchangeName}: " . $exception->getMessage());
		}
	}

	/**
	 * Connect to the Gate exchange using API credentials.
	 * Initializes Wallet API and Spot API instances.
	 * 
	 * @return bool True if connection successful, false otherwise.
	 */
	public function connect(): bool {
		try {
			$key = $this->config->getKey();
			$secret = $this->config->getSecret();
			$config = Configuration::getDefaultConfiguration()->setKey($key)->setSecret($secret);

			// Create Wallet API instance.
			$this->walletApi = new WalletApi(null, $config);

			// Create Spot API instance.
			$this->spotApi = new SpotApi(null, $config);

			return true;
		} catch (ApiException $e) {
			$this->logger->error("Failed to connect to exchange {$this->exchangeName}: " . $e->getMessage());
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
	 * Cancel all active spot orders for a specific trading pair.
	 * 
	 * @param string $pair Trading pair to cancel orders for.
	 * @throws ApiException If API request fails.
	 */
	private function cancelOrders(string $pair): void {
		try {
			$result = $this->spotApi->cancelOrders($pair);
			var_dump($result);
		} catch (ApiException $e) {
			$this->logger->error("Failed to cancel orders for {$pair} on {$this->exchangeName}: " . $e->getMessage());
		}
	}

	/**
	 * Refresh spot orders information.
	 * Currently commented out - placeholder for future implementation.
	 */
	protected function refreshSpotOrders(): void {
		/*$associate_array = [];
		$associate_array['currency_pair'] = 'POPCAT_USDT'; // string | Retrieve results with specified currency pair. It is required for open orders, but optional for finished ones.
		$associate_array['status'] = 'open'; // string | List orders based on status  `open` - order is waiting to be filled `finished` - order has been filled or cancelled
		$associate_array['page'] = 1; // int | Page number
		$associate_array['limit'] = 100; // int | Maximum number of records to be returned. If `status` is `open`, maximum of `limit` is 100
		$associate_array['account'] = 'spot'; // string | Specify operation account. Default to spot and margin account if not specified. Set to `cross_margin` to operate against margin account.  Portfolio margin account must set to `cross_margin` only
		$result = $this->spotApi->listOrders($associate_array);
		var_dump($result[0]);*/
	}

	/**
	 * Get market instance for a trading pair.
	 * Creates a new market with candle data from Gate API.
	 * 
	 * @param Pair $pair Trading pair.
	 * @return Market|null Market instance or null if not found.
	 */
	public function getMarket(Pair $pair): ?Market {
		$candlesData = $this->getCandles($pair, 200);
		if (empty($candlesData)) {
			return null;
		}
		
		$market = new Market($pair, $this);
		$market->setCandles($candlesData);
		return $market;
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
	 * @param Pair $pair Trading pair.
	 * @param int $limit Number of candles to retrieve.
	 * @return Candle[] Array of candle objects sorted by time.
	 */
	public function getCandles(Pair $pair, int $limit = 100): array {
		try {
			$ticker = $pair->getTicker();
			$timeframe = $pair->getTimeframe();
			$gateInterval = $this->timeframeToGateInterval($timeframe);
			
			if (!$gateInterval) {
				$this->logger->error("Unknown timeframe {$timeframe->value} for Gate.");
				return [];
			}

			// Convert ticker format from BTC/USDT to BTC_USDT for Gate API.
			$symbol = str_replace('/', '_', $ticker);
			
			$params = [
				'currency_pair' => $symbol,
				'interval' => $gateInterval,
				'limit' => $limit,
			];
			$response = $this->spotApi->listCandlesticks($params);
			$candles = [];
			
			foreach ($response as $item) {
				// Gate API returns array: [timestamp, volume, close, high, low, open].
				$candles[] = new Candle(
					(int)$item[0], // timestamp.
					(float)$item[5], // open.
					(float)$item[3], // high.
					(float)$item[4], // low.
					(float)$item[2], // close.
					(float)$item[1]  // volume.
				);
			}

			// Sort candles by time (oldest to newest).
			usort($candles, function($a, $b) {
				return $a->getOpenTime() - $b->getOpenTime();
			});

			return $candles;
		} catch (ApiException $exception) {
			$this->logger->error("Failed to get candles for {$ticker} on {$this->exchangeName}: " . $exception->getMessage());
			return [];
		}
	}
	
	/**
	 * Update balance information.
	 * Delegates to refreshAccountBalance method.
	 */
	protected function updateBalance(): void {
		$this->refreshAccountBalance();
	}
	
	/**
	 * Update market information for all markets.
	 * Fetches fresh candle data for each configured market.
	 */
	protected function updateMarkets(): void {
		foreach ($this->markets as $ticker => $market) {
			// First, let's determine the type of market.
			$marketType = $market->getMarketType();
			
			// If the market type is spot, we need to fetch spot candles.
			if ($marketType->isSpot()) {
				if (isset($this->spotPairs[$ticker])) {
					$pair = $this->spotPairs[$ticker];
					$candles = $this->getCandles($pair);
					$market->setCandles($candles);
				}
			}
			
			// If the market type is futures, we need to fetch futures candles.
			if ($marketType->isFutures()) {
				if (isset($this->futuresPairs[$ticker])) {
					$pair = $this->futuresPairs[$ticker];
					$candles = $this->getCandles($pair);
					$market->setCandles($candles);
				}
			}
		}
	}
}
