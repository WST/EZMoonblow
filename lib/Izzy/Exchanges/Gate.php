<?php

namespace Izzy\Exchanges;

use Exception;
use GateApi\Api\SpotApi;
use GateApi\Api\WalletApi;
use GateApi\ApiException;
use GateApi\Configuration;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Candle;
use Izzy\Financial\Money;
use Izzy\Financial\Position;
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
		$config = Configuration::getDefaultConfiguration()->setKey($key)->setSecret($secret);

		// Create Wallet API instance.
		$this->walletApi = new WalletApi(null, $config);

		// Create Spot API instance.
		$this->spotApi = new SpotApi(null, $config);

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
	 * @inheritDoc
	 */
	public function getCurrentPrice(IMarket $market): ?Money {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$params = ['currency_pair' => $ticker];

			$response = $this->spotApi->listTickers($params);
			if (!empty($response)) {
				// listTickers returns an array, we need the first item
				$tickerData = $response[0] ?? null;
				if ($tickerData && method_exists($tickerData, 'getLast')) {
					return new Money($tickerData->getLast());
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
			// Safety check: limit position size to $100
			if ($amount->getAmount() > 100.0) {
				$this->logger->warning("Position size $amount exceeds $100 limit, reducing to $100");
				$amount = new Money(100.0, $amount->getCurrency());
			}
			
			$params = [
				'currency_pair' => $ticker,
				'side' => 'buy',
				'amount' => $this->calculateQuantity($market, $amount, $price),
				'type' => $price ? 'limit' : 'market'
			];

			if ($price) {
				$params['price'] = (string)$price;
			}

			$response = $this->spotApi->createOrder($params);
			
			if ($response && $response->getId()) {
				$this->logger->info("Successfully opened long position for $ticker: $amount");
				
				// Save position to database
				$currentPrice = $this->getCurrentPrice($market);
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
				$this->logger->error("Failed to open long position for $ticker: invalid response");
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to open long position for $ticker: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Open a short position (futures only).
	 *
	 * @param IMarket $market
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openShort(IMarket $market, Money $amount, ?float $price = null): bool {
		// Gate.io doesn't support futures trading in the basic API
		// This is a placeholder implementation
		$ticker = $market->getPair()->getExchangeTicker($this);
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
}
