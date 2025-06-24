<?php

namespace Izzy\Exchanges;

use ByBit\SDK\ByBitApi;
use ByBit\SDK\Enums\AccountType;
use ByBit\SDK\Exceptions\HttpException;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Candle;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Interfaces\IMarket;

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
		} catch (\Exception $e) {
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
	 * Refresh total account balance information.
	 * NOTE: Earn API is not implemented in the SDK.
	 */
	protected function updateBalance(): void {
		try {
			$params = ['accountType' => AccountType::UNIFIED];
			$info = $this->api->accountApi()->getWalletBalance($params);
			if (!isset($info['list'][0]['totalEquity'])) {
				$this->logger->error("Failed to get balance: invalid response format from Bybit");
				return;
			}
			$value = (float)$info['list'][0]['totalEquity'];
			$totalBalance = new Money($value);
			$this->setBalance($totalBalance);
		} catch (HttpException $exception) {
			$this->logger->error("Failed to update wallet balance on {$this->exchangeName}: " . $exception->getMessage());
		} catch (\Exception $e) {
			$this->logger->error("Unexpected error while updating balance on {$this->exchangeName}: " . $e->getMessage());
		}
	}

	/**
	 * Refresh spot orders information.
	 */
	protected function refreshSpotOrders(): void {

	}

	/**
	 * Get market instance for a trading pair.
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
	 * Get candles for the specified trading pair and timeframe.
	 *
	 * @param Pair $pair Trading pair.
	 * @param int $limit Number of candles (maximum 1000).
	 * @param int|null $startTime Start timestamp in milliseconds.
	 * @param int|null $endTime End timestamp in milliseconds.
	 * @return Candle[] Array of candle objects.
	 */
	public function getCandles(
		Pair $pair,
		int $limit = 100,
		?int $startTime = null,
		?int $endTime = null
	): array {
		try {
			$params = [
				'category' => $this->getBybitCategory($pair),
				'symbol' => $pair->getTicker(),
				'interval' => $this->timeframeToBybitInterval($pair->getTimeframe()),
				'limit' => $limit
			];
			if ($startTime !== null) {
				$params['start'] = $startTime;
			}
			if ($endTime !== null) {
				$params['end'] = $endTime;
			}

			$this->logger->info("Sending getKline request to Bybit with params: " . json_encode($params));
			$response = $this->api->marketApi()->getKline($params);
			$candles = [];

			if (empty($response['list'])) {
				return []; // No candles received.
			}

			foreach ($response['list'] as $item) {
				$candles[] = new Candle(
					(int)($item[0] / 1000), // timestamp (convert from milliseconds to seconds).
					(float)$item[1], // open.
					(float)$item[2], // high.
					(float)$item[3], // low.
					(float)$item[4], // close.
					(float)$item[5]  // volume.
				);
			}

			// Sort candles by time (oldest to newest).
			usort($candles, function($a, $b) {
				return $a->getOpenTime() - $b->getOpenTime();
			});

			return $candles;
		} catch (HttpException $exception) {
			$this->logger->error("Failed to get candles for {$symbol} on {$this->exchangeName}: " . $exception->getMessage());
			return [];
		} catch (\Exception $e) {
			$this->logger->error("Unexpected error while getting candles for {$symbol} on {$this->exchangeName}: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * Get current balance amount.
	 * 
	 * @return float Current balance amount.
	 */
	protected function getBalance(): float {
		return $this->totalBalance ? $this->totalBalance->getAmount() : 0.0;
	}

	/**
	 * Check if orders should be updated.
	 * Currently disabled as trading is not implemented.
	 * 
	 * @return bool Always returns false.
	 */
	protected function shouldUpdateOrders(): bool {
		return false;
	}

	/**
	 * Update market information for all markets.
	 */
	protected function updateMarkets(): void {
		foreach ($this->markets as $ticker => $market) {
			// First, let's determine the type of market.
			$marketType = $market->getMarketType();
			
			// If the market type is spot, we need to fetch spot candles.
			if ($marketType->isSpot()) {
				$pair = $this->spotPairs[$ticker];
				$candles = $this->getCandles($pair);
				$market->setCandles($candles);
			}
			
			// If the market type is futures, we need to fetch futures candles.
			if ($marketType->isFutures()) {
				$pair = $this->futuresPairs[$ticker];
				$candles = $this->getCandles($pair);
				$market->setCandles($candles);
			}
		}
	}

	/**
	 * Get Bybit category for a trading pair.
	 * 
	 * @param Pair $pair Trading pair.
	 * @return string Bybit category string.
	 * @throws \InvalidArgumentException If pair type is unknown.
	 */
	private function getBybitCategory(Pair $pair): string {
		if ($pair->isSpot()) {
			return 'spot';
		} elseif ($pair->isFutures()) {
			return 'linear';
		} elseif ($pair->isInverseFutures()) {
			return 'inverse';
		} else {
			throw new \InvalidArgumentException("Unknown pair type for Bybit: " . $pair->getType());
		}
	}
}
