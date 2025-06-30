<?php

namespace Izzy\Exchanges\KuCoin;

use Exception;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\AbstractExchangeDriver;
use Izzy\Financial\Candle;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPosition;
use KuCoin\SDK\Auth;
use KuCoin\SDK\KuCoinApi;
use KuCoin\SDK\PrivateApi\Account;
use KuCoin\SDK\PrivateApi\Order;
use KuCoin\SDK\PublicApi\Currency;
use KuCoin\SDK\PublicApi\Symbol;

/**
 * Driver for working with KuCoin exchange.
 * Provides integration with KuCoin cryptocurrency exchange API.
 */
class KuCoin extends AbstractExchangeDriver
{
	/** @var string Exchange name identifier. */
	protected string $exchangeName = 'KuCoin';
	
	/** @var Account|null Account API instance for balance operations. */
	private ?Account $account = null;

	/** @var Currency|null Currency API instance for price data. */
	private ?Currency $currency = null;

	/** @var Order|null Order API instance for trading operations. */
	private ?Order $order = null;

	/** @var Symbol|null Symbol API instance for market data. */
	private ?Symbol $symbol = null;

	/**
	 * Connect to the KuCoin exchange using API credentials.
	 *
	 * @return bool True if connection successful, false otherwise.
	 */
	public function connect(): bool {
		try {
			KuCoinApi::setBaseUri('https://api.kucoin.com');
			$key = $this->config->getKey();
			$secret = $this->config->getSecret();
			$password = $this->config->getPassword();
			$auth = new Auth($key, $secret, $password, Auth::API_KEY_VERSION_V2);

			$this->account = new Account($auth);
			$this->currency = new Currency($auth);
			$this->order = new Order($auth);
			$this->symbol = new Symbol($auth);

			// Test connection by requesting account info.
			$accountList = $this->account->getList();
			if (empty($accountList)) {
				$this->logger->error("Failed to get account data from {$this->getName()}, connection not established.");
				return false;
			}

			return true;
		} catch (Exception $e) {
			$this->logger->error("Failed to connect to exchange {$this->getName()}: " . $e->getMessage() . ".");
			return false;
		}
	}

	/**
	 * Disconnect from the exchange.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$this->account = null;
		$this->currency = null;
		$this->order = null;
		$this->symbol = null;
	}

	/**
	 * Refresh total account balance information from KuCoin exchange.
	 * Calculates total balance in USDT equivalent.
	 *
	 * @return void
	 */
	public function updateBalance(): void {
		try {
			$accountList = $this->account->getList();
			$currencies = array_map(fn($price) => floatval($price), $this->currency->getPrices());

			$sum = array_reduce($accountList, function ($carry, $item) use ($currencies) {
				$symbol = $item['currency'];
				$balance = floatval($item['balance']);
				$price = $currencies[$symbol] ?? 0;
				return $carry + ($balance * $price);
			}, 0.0);

			$result = Money::from($sum);
			$this->saveBalance($result);
		} catch (Exception $e) {
			$this->logger->error("Failed to update wallet balance on {$this->getName()}: " . $e->getMessage() . ".");
		}
	}

	/**
	 * Convert internal timeframe to KuCoin interval format.
	 *
	 * @param TimeFrameEnum $timeframe Internal timeframe enum.
	 * @return string|null KuCoin interval string or null if not supported.
	 */
	private function timeframeToKuCoinInterval(TimeFrameEnum $timeframe): ?string {
		return match ($timeframe->value) {
			'1m' => '1min',
			'3m' => '3min',
			'5m' => '5min',
			'15m' => '15min',
			'30m' => '30min',
			'1h' => '1hour',
			'2h' => '2hour',
			'4h' => '4hour',
			'6h' => '6hour',
			'12h' => '12hour',
			'1d' => '1day',
			'1w' => '1week',
			'1M' => '1month',
			default => null,
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function getCandles(
		IPair $pair,
		int $limit = 100,
		?int $startTime = null,
		?int $endTime = null
	): array {
		$ticker = $pair->getExchangeTicker($this);
		try {
			$timeframe = $pair->getTimeframe();
			$kuCoinInterval = $this->timeframeToKuCoinInterval($timeframe);

			if (!$kuCoinInterval) {
				$this->logger->error("Unknown timeframe $timeframe->value for KuCoin.");
				return [];
			}

			// KuCoin API uses seconds, not milliseconds, for time.
			if ($startTime !== null && $endTime !== null) {
				$startAt = (int)($startTime / 1000);
				$endAt = (int)($endTime / 1000);
			} else {
				// Calculate time range based on limit and interval.
				$endAt = time();
				$intervalSeconds = $this->getIntervalSeconds($kuCoinInterval);
				$startAt = $endAt - ($limit * $intervalSeconds);
			}

			$response = $this->symbol->getKLines($ticker, $startAt, $endAt, $kuCoinInterval);

			if (empty($response)) {
				return [];
			}

			$candles = array_map(
				fn($item) => new Candle(
					(int)$item[0], // timestamp.
					(float)$item[1], // open.
					(float)$item[3], // high.
					(float)$item[4], // low.
					(float)$item[2], // close.
					(float)$item[5]  // volume.
				),
				$response
			);

			// Sort candles by time (oldest to newest).
			usort($candles, fn($a, $b) => $a->getOpenTime() - $b->getOpenTime());

			// Limit the number of candles according to $limit.
			if (count($candles) > $limit) {
				$candles = array_slice($candles, -$limit);
			}

			return $candles;
		} catch (Exception $e) {
			$this->logger->error("Failed to get candles for $ticker on {$this->getName()}: " . $e->getMessage() . ".");
			return [];
		}
	}

	/**
	 * Get interval duration in seconds for KuCoin interval format.
	 *
	 * @param string $interval KuCoin interval string.
	 * @return int Duration in seconds.
	 */
	private function getIntervalSeconds(string $interval): int {
		return match ($interval) {
			'1min' => 60,
			'3min' => 180,
			'5min' => 300,
			'15min' => 900,
			'30min' => 1800,
			'1hour' => 3600,
			'2hour' => 7200,
			'4hour' => 14400,
			'6hour' => 21600,
			'8hour' => 28800,
			'12hour' => 43200,
			'1day' => 86400,
			'1week' => 604800,
			'1month' => 2592000,
			default => 0,
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function getCurrentPrice(IMarket $market): ?Money {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			$response = $this->symbol->getTicker($ticker);

			if ($response && isset($response['price'])) {
				return Money::from($response['price']);
			}

			$this->logger->error("Failed to get current price for $ticker: invalid response.");
			return null;
		} catch (Exception $e) {
			$this->logger->error("Failed to get current price for $ticker: " . $e->getMessage() . ".");
			return null;
		}
	}

	/**
	 * Open a long position.
	 *
	 * @param IMarket $market Market instance.
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function openLong(IMarket $market, Money $amount, ?float $price = null): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			// Safety check: limit position size to $100.
			if ($amount->getAmount() > 100.0) {
				$this->logger->warning("Position size $amount exceeds $100 limit, reducing to $100.");
				$amount->setAmount(100.0);
			}

			$params = [
				'clientOid' => uniqid(),
				'symbol' => $ticker,
				'side' => 'buy',
				'type' => $price ? 'limit' : 'market',
				'funds' => (string)$amount->getAmount(),
			];

			if ($price) {
				$params['price'] = (string)$price;
			}

			$response = $this->order->createOrder($params);

			if ($response && isset($response['orderId'])) {
				$this->logger->info("Successfully opened long position for $ticker: $amount.");

				$currentPrice = $this->getCurrentPrice($market);
				if ($currentPrice) {
					$this->database->savePosition(
						$this->getName(),
						$ticker,
						'spot',
						'long',
						$currentPrice,
						$currentPrice,
						$amount->getAmount(),
						$amount->getCurrency(),
						'open',
						$response['orderId'],
						$response['orderId']
					);
				}

				return true;
			} else {
				$this->logger->error("Failed to open long position for $ticker: invalid response.");
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to open long position for $ticker: " . $e->getMessage() . ".");
			return false;
		}
	}

	/**
	 * Open a short position (futures only).
	 * KuCoin does not support futures trading in the basic API.
	 *
	 * @param IMarket $market Market instance.
	 * @param Money $amount Amount to invest.
	 * @param float|null $price Limit price (null for market order).
	 * @return bool Always returns false.
	 */
	public function openShort(IMarket $market, Money $amount, ?float $price = null): bool {
		$ticker = $market->getPair()->getExchangeTicker($this);
		$this->logger->warning("Short positions are TODO");
		return false;
	}

	/**
	 * Place a market order to buy additional volume (DCA).
	 *
	 * @param IMarket $market Market instance.
	 * @param Money $amount Amount to buy.
	 * @return bool True if order placed successfully, false otherwise.
	 */
	public function buyAdditional(IMarket $market, Money $amount): bool {
		$pair = $market->getPair();
		$ticker = $pair->getExchangeTicker($this);
		try {
			// Safety check: limit DCA amount to $50.
			if ($amount->getAmount() > 50.0) {
				$this->logger->warning("DCA amount $amount exceeds $50 limit, reducing to $50.");
				$amount = Money::from(50.0, $amount->getCurrency());
			}

			$params = [
				'clientOid' => uniqid(),
				'symbol' => $ticker,
				'side' => 'buy',
				'type' => 'market',
				'funds' => (string)$amount->getAmount(),
			];

			$response = $this->order->createOrder($params);

			if ($response && isset($response['orderId'])) {
				$this->logger->info("Successfully executed DCA buy for $ticker: $amount.");
				return true;
			} else {
				$this->logger->error("Failed to execute DCA buy for $ticker: invalid response.");
				return false;
			}
		} catch (Exception $e) {
			$this->logger->error("Failed to execute DCA buy for $ticker: " . $e->getMessage() . ".");
			return false;
		}
	}

	public function sellAdditional(IMarket $market, Money $amount): bool {
		// TODO: Implement sellAdditional() method.
		return false;
	}

	/**
	 * KuCoin uses tickers like "BTC-USDT" for pairs.
	 *
	 * @param IPair $pair Trading pair instance.
	 * @return string Ticker string for KuCoin.
	 */
	public function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency() . '-' . $pair->getQuoteCurrency();
	}

	public function getSpotBalanceByCurrency(string $coin): Money {
		return Money::from(0.0, $coin);
	}

	public function getCurrentFuturesPosition(IMarket $market): IPosition|false {
		return false;
	}
}
