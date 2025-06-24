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
 * Драйвер для работы с биржей Bybit
 */
class Bybit extends AbstractExchangeDriver
{
	protected string $exchangeName = 'Bybit';

	// Общий баланс всех средств на бирже, пересчитанный в доллары
	private ?Money $totalBalance = null;

	// API для общения с биржей
	protected ByBitApi $api;

	/**
	 * List of pairs.
	 * @var array 
	 */
	protected array $pairs = [];

	/**
	 * List of the markets being traded on / monitored.
	 * @var IMarket[] 
	 */
	protected array $markets = [];

	public function connect(): bool {
		try {
			$key = $this->config->getKey();
			$secret = $this->config->getSecret();
			$this->api = new ByBitApi($key, $secret, ByBitApi::PROD_API_URL);
			
			// Проверяем подключение через запрос свечей для BTCUSDT
			$testResponse = $this->api->marketApi()->getKline([
				'category' => 'spot',
				'symbol' => 'BTCUSDT',
				'interval' => '1',
				'limit' => 1
			]);
			
			if (!isset($testResponse['list'])) {
				$this->logger->error("Не удалось получить тестовые данные от Bybit, подключение не установлено.");
				return false;
			}
			
			return true;
		} catch (\Exception $e) {
			$this->logger->error("Не удалось подключиться к бирже {$this->exchangeName}: " . $e->getMessage());
			return false;
		}
	}

	public function disconnect(): void {
		// TODO: Implement disconnect() method.
	}

	/**
	 * Refresh total account balance info.
	 * NOTE: Earn API is not implemented in the SDK.
	 * @return void
	 */
	protected function updateBalance(): void {
		try {
			$params = ['accountType' => AccountType::UNIFIED];
			$info = $this->api->accountApi()->getWalletBalance($params);
			
			if (!isset($info['list'][0]['totalEquity'])) {
				$this->logger->error("Не удалось получить баланс: неверный формат ответа от Bybit");
				return;
			}
			
			$value = (float)$info['list'][0]['totalEquity'];
			if (is_null($this->totalBalance)) {
				$this->totalBalance = new Money($value);
			} else {
				$this->totalBalance->setAmount($value);
			}
			$this->logger->info("Баланс на {$this->exchangeName}: {$this->totalBalance}");
			$this->database->setExchangeBalance($this->exchangeName, $this->totalBalance);
		} catch (HttpException $exception) {
			$this->logger->error("Не удалось обновить баланс кошелька на {$this->exchangeName}: " . $exception->getMessage());
		} catch (\Exception $e) {
			$this->logger->error("Неожиданная ошибка при обновлении баланса на {$this->exchangeName}: " . $e->getMessage());
		}
	}

	protected function refreshSpotOrders(): void {

	}

	public function getMarket(Pair $pair): ?Market {
		$ticker = $pair->getTicker();
		$timeframe = $pair->getTimeframe();
		$marketType = $pair->getMarketType();
		$bybitInterval = $this->timeframeToBybitInterval($timeframe);
		
		if (!$bybitInterval) {
			$this->logger->error("Неизвестный таймфрейм {$timeframe} для Bybit.");
			return null;
		}

		$bybitCategory = '';
		if ($marketType->isSpot()) {
			$bybitCategory = 'spot';
		} else if ($marketType->isFutures()) {
			$bybitCategory = 'spot';
		} else {
			$this->logger->error("The selected market type for exchange Bybit, pair {$ticker} is not supported.");
		}

		$candlesData = $this->getCandles($this->formatPair($ticker), $bybitInterval, $bybitCategory, 200);
		if (empty($candlesData)) {
			return null;
		}
		
		$market = new Market($pair, $this);
		$market->setCandles($candlesData);
		return $market;
	}

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
	 * Получает свечи для указанной торговой пары и таймфрейма
	 *
	 * @param Pair $pair
	 * @param int $limit Количество свечей (максимум 1000)
	 * @param int|null $startTime Начальная временная метка (ms)
	 * @param int|null $endTime Конечная временная метка (ms)
	 * @return Candle[]
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
				return []; // No candles received
			}

			foreach ($response['list'] as $item) {
				$candles[] = new Candle(
					(int)($item[0] / 1000), // timestamp (конвертируем из миллисекунд в секунды)
					(float)$item[1], // open
					(float)$item[2], // high
					(float)$item[3], // low
					(float)$item[4], // close
					(float)$item[5]  // volume
				);
			}

			// Сортируем свечи по времени (от старых к новым)
			usort($candles, function($a, $b) {
				return $a->getOpenTime() - $b->getOpenTime();
			});

			return $candles;
		} catch (HttpException $exception) {
			$this->logger->error("Не удалось получить свечи для {$symbol} на {$this->exchangeName}: " . $exception->getMessage());
			return [];
		} catch (\Exception $e) {
			$this->logger->error("Неожиданная ошибка при получении свечей для {$symbol} на {$this->exchangeName}: " . $e->getMessage());
			return [];
		}
	}

	protected function getBalance(): float {
		return $this->totalBalance ? $this->totalBalance->getAmount() : 0.0;
	}

	/**
	 * We don’t trade for now.
	 * @return bool
	 */
	protected function shouldUpdateOrders(): bool {
		return false;
	}

	/**
	 * Update market information.
	 * @return void
	 */
	protected function updateMarkets(): void {
		foreach ($this->markets as $ticker => $market) {
			$pair = $this->pairs[$ticker];
			$candles = $this->getCandles($pair);
			$market->setCandles($candles);
		}
	}
}
