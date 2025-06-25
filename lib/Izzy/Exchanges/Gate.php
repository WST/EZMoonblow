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
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IPair;

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
	 * @param IPair $pair Trading pair to cancel orders for.
	 */
	private function cancelOrders(IPair $pair): void {
		$ticker = $pair->getExchangeTicker($this);
		try {
			$result = $this->spotApi->cancelOrders($ticker);
			var_dump($result);
		} catch (ApiException $e) {
			$this->logger->error("Failed to cancel orders for {$ticker} on {$this->exchangeName}: " . $e->getMessage());
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
	 * @param IPair $pair Trading pair.
	 * @return IMarket|null Market instance or null if not found.
	 */
	public function getMarket(IPair $pair): ?IMarket {
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
				$this->logger->error("Unknown timeframe {$timeframe->value} for Gate.");
				return [];
			}
			
			$params = [
				'currency_pair' => $ticker,
				'interval' => $gateInterval,
				'limit' => $limit,
			];
			$response = $this->spotApi->listCandlesticks($params);
			
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
	 * @inheritDoc
	 */
	public function getCurrentPrice(IPair $pair): ?float {
		$ticker = $pair->getExchangeTicker($this);
		try {
			$params = ['currency_pair' => $ticker];

			$response = $this->spotApi->listTickers($params);
			if ($response && !empty($response)) {
				// listTickers returns an array, we need the first item
				$tickerData = $response[0] ?? null;
				if ($tickerData && method_exists($tickerData, 'getLast')) {
					return (float)$tickerData->getLast();
				}
			}

			$this->logger->error("Failed to get current price for {$ticker}: invalid response");
			return null;
		} catch (\Exception $e) {
			$this->logger->error("Failed to get current price for {$ticker}: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPosition(IPair $pair): ?IPosition {
		$ticker = $pair->getExchangeTicker($this);
		try {
			$baseCurrency = $pair->getBaseCurrency();
			
			$params = ['currency' => $baseCurrency];

			$response = $this->walletApi->getTotalBalance($params);
			
			if ($response && $response->getTotal() && (float)$response->getTotal()->getAmount() > 0) {
				// We have a position in this currency
				$currentPrice = $this->getCurrentPrice($pair);
				if (!$currentPrice) {
					return null;
				}

				return new \Izzy\Financial\Position(
					new Money((float)$response->getTotal()->getAmount(), $baseCurrency),
					'long',
					$currentPrice, // Approximate entry price
					$currentPrice,
					'open',
					''
				);
			}
			
			return null;
		} catch (\Exception $e) {
			$this->logger->error("Failed to get current position for {$ticker}: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Open a long position.
	 *
	 * @param IPair $pair
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openLong(IPair $pair, Money $amount, ?float $price = null): bool {
		$ticker = $pair->getExchangeTicker($this);
		try {
			// Safety check: limit position size to $100
			if ($amount->getAmount() > 100.0) {
				$this->logger->warning("Position size {$amount} exceeds $100 limit, reducing to $100");
				$amount = new Money(100.0, $amount->getCurrency());
			}
			
			$params = [
				'currency_pair' => $ticker,
				'side' => 'buy',
				'amount' => $this->calculateQuantity($pair, $amount, $price),
				'type' => $price ? 'limit' : 'market'
			];

			if ($price) {
				$params['price'] = (string)$price;
			}

			$response = $this->spotApi->createOrder($params);
			
			if ($response && $response->getId()) {
				$this->logger->info("Successfully opened long position for {$ticker}: {$amount}");
				
				// Save position to database
				$currentPrice = $this->getCurrentPrice($pair);
				if ($currentPrice) {
					$this->database->savePosition(
						$this->exchangeName,
						$ticker,
						'spot',
						'long',
						$currentPrice,
						$currentPrice,
						$amount->getAmount(),
						$amount->getCurrency(),
						'open',
						$response->getId(),
						$response->getId()
					);
				}
				
				return true;
			} else {
				$this->logger->error("Failed to open long position for {$ticker}: invalid response");
				return false;
			}
		} catch (\Exception $e) {
			$this->logger->error("Failed to open long position for {$ticker}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Open a short position (futures only).
	 *
	 * @param IPair $pair
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openShort(IPair $pair, Money $amount, ?float $price = null): bool {
		// Gate.io doesn't support futures trading in the basic API
		// This is a placeholder implementation
		$ticker = $pair->getExchangeTicker($this);
		$this->logger->warning("Short positions not supported on Gate.io for {$ticker}");
		return false;
	}

	/**
	 * Close an existing position.
	 *
	 * @param IPair $pair
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function closePosition(IPair $pair, ?float $price = null): bool {
		$ticker = $pair->getExchangeTicker($this);
		try {
			$currentPosition = $this->getCurrentPosition($pair);
			if (!$currentPosition) {
				$this->logger->warning("No position to close for {$ticker}");
				return true; // Consider it successful if no position exists
			}
			
			$params = [
				'currency_pair' => $ticker,
				'side' => 'sell',
				'amount' => (string)$currentPosition->getVolume()->getAmount(),
				'type' => $price ? 'limit' : 'market'
			];

			if ($price) {
				$params['price'] = (string)$price;
			}

			$response = $this->spotApi->createOrder($params);
			
			if ($response && $response->getId()) {
				$this->logger->info("Successfully closed position for {$ticker}");
				
				// Update position status in database
				$dbPosition = $this->database->getCurrentPosition($this->exchangeName, $ticker);
				if ($dbPosition) {
					$this->database->closePosition($dbPosition['id']);
				}
				
				return true;
			} else {
				$this->logger->error("Failed to close position for {$ticker}: invalid response");
				return false;
			}
		} catch (\Exception $e) {
			$this->logger->error("Failed to close position for {$ticker}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Place a market order to buy additional volume (DCA).
	 *
	 * @param IPair $pair
	 * @param Money $amount Amount to buy.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function buyAdditional(IPair $pair, Money $amount): bool {
		$ticker = $pair->getExchangeTicker($this);
		try {
			// Safety check: limit DCA amount to $50
			if ($amount->getAmount() > 50.0) {
				$this->logger->warning("DCA amount {$amount} exceeds $50 limit, reducing to $50");
				$amount = new Money(50.0, $amount->getCurrency());
			}
			
			$params = [
				'currency_pair' => $ticker,
				'side' => 'buy',
				'amount' => $this->calculateQuantity($pair, $amount, null),
				'type' => 'market'
			];

			$response = $this->spotApi->createOrder($params);
			
			if ($response && $response->getId()) {
				$this->logger->info("Successfully executed DCA buy for {$ticker}: {$amount}");
				return true;
			} else {
				$this->logger->error("Failed to execute DCA buy for {$ticker}: invalid response");
				return false;
			}
		} catch (\Exception $e) {
			$this->logger->error("Failed to execute DCA buy for {$ticker}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function sellAdditional(IPair $pair, Money $amount): bool {
		$ticker = $pair->getExchangeTicker($this);
		try {
			// Safety check: limit sell amount to $50
			if ($amount->getAmount() > 50.0) {
				$this->logger->warning("Sell amount {$amount} exceeds $50 limit, reducing to $50");
				$amount = new Money(50.0, $amount->getCurrency());
			}
			
			$params = [
				'currency_pair' => $ticker,
				'side' => 'sell',
				'amount' => $this->calculateQuantity($pair, $amount, null),
				'type' => 'market'
			];

			$response = $this->spotApi->createOrder($params);
			
			if ($response && $response->getId()) {
				$this->logger->info("Successfully executed sell for {$ticker}: {$amount}");
				return true;
			} else {
				$this->logger->error("Failed to execute sell for {$ticker}: invalid response");
				return false;
			}
		} catch (\Exception $e) {
			$this->logger->error("Failed to execute sell for {$ticker}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Calculate quantity based on amount and price.
	 *
	 * @param IPair $pair
	 * @param Money $amount Amount in USDT.
	 * @param float|null $price Price per unit.
	 * @return string Quantity as string.
	 */
	private function calculateQuantity(IPair $pair, Money $amount, ?float $price): string {
		$ticker = $pair->getExchangeTicker($this);
		if ($price) {
			$quantity = $amount->getAmount() / $price;
		} else {
			// For market orders, use a rough estimate
			$currentPrice = $this->getCurrentPrice($pair);
			$quantity = $currentPrice ? $amount->getAmount() / $currentPrice : 0.001;
		}
		
		// Round to 6 decimal places for most cryptocurrencies
		return number_format($quantity, 6, '.', '');
	}

	/**
	 * Gate uses tickers like “BTC_USDT” for pairs.
	 * @param IPair $pair
	 * @return string
	 */
	public function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency() . '_' . $pair->getQuoteCurrency();
	}
}
