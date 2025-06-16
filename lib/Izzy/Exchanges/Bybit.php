<?php

namespace Izzy\Exchanges;

use ByBit\SDK\ByBitApi;
use ByBit\SDK\Enums\AccountType;
use ByBit\SDK\Exceptions\HttpException;
use Izzy\Candle;
use Izzy\Market;
use Izzy\Money;

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
				$this->log("Не удалось получить тестовые данные от Bybit, подключение не установлено.");
				return false;
			}
			
			return true;
		} catch (\Exception $e) {
			$this->log("Не удалось подключиться к бирже {$this->exchangeName}: " . $e->getMessage());
			return false;
		}
	}

	public function disconnect(): void {
		// TODO: Implement disconnect() method.
	}

	protected function updateBalance(): void {
		try {
			$params = ['accountType' => AccountType::UNIFIED];
			$info = $this->api->accountApi()->getWalletBalance($params);
			
			if (!isset($info['list'][0]['totalEquity'])) {
				$this->log("Не удалось получить баланс: неверный формат ответа от Bybit");
				return;
			}
			
			$value = (float)$info['list'][0]['totalEquity'];
			if (is_null($this->totalBalance)) {
				$this->totalBalance = new Money($value);
			} else {
				$this->totalBalance->setAmount($value);
			}
			$this->log("Баланс на {$this->exchangeName}: {$this->totalBalance}");
		} catch (HttpException $exception) {
			$this->log("Не удалось обновить баланс кошелька на {$this->exchangeName}: " . $exception->getMessage());
		} catch (\Exception $e) {
			$this->log("Неожиданная ошибка при обновлении баланса на {$this->exchangeName}: " . $e->getMessage());
		}
	}

	protected function refreshSpotOrders(): void {

	}

	/**
	 * Получить рынок (Market) по объекту Pair
	 * @param Pair $pair
	 * @return Market|null
	 */
	public function getMarket(Pair $pair): ?Market
	{
		$ticker = $pair->getTicker();
		$timeframe = $pair->getTimeframe();
		$marketType = $pair->getMarketType();
		$bybitInterval = $this->convertTimeframeToBybitInterval($timeframe);
		if (!$bybitInterval) {
			$this->log("Неизвестный таймфрейм {$timeframe} для Bybit.");
			return null;
		}

		$bybitCategory = '';
		switch ($marketType) {
			case 'spot':
				$bybitCategory = 'spot';
				break;
			case 'futures':
				$bybitCategory = 'linear';
				break;
			default:
				$this->log("Неизвестный тип рынка {$marketType} для Bybit.");
				return null;
		}

		$symbol = str_replace('/', '', $ticker);
		$candlesData = $this->getKlines($symbol, $bybitInterval, $bybitCategory, 200);
		if (empty($candlesData)) {
			return null;
		}
		$market = new Market($ticker, $timeframe, $this->exchangeName, $marketType, $candlesData);
		return $market;
	}

	private function convertTimeframeToBybitInterval(string $timeframe): ?string
	{
		switch ($timeframe) {
			case '1m': return '1';
			case '3m': return '3';
			case '5m': return '5';
			case '15m': return '15';
			case '30m': return '30';
			case '1h': return '60';
			case '2h': return '120';
			case '4h': return '240';
			case '6h': return '360';
			case '12h': return '720';
			case '1d': return 'D';
			case '1w': return 'W';
			case '1M': return 'M';
			default: return null;
		}
	}

	private function convertTimeframeToMilliseconds(string $timeframe): ?int
	{
		switch ($timeframe) {
			case '1m': return 60 * 1000;
			case '3m': return 3 * 60 * 1000;
			case '5m': return 5 * 60 * 1000;
			case '15m': return 15 * 60 * 1000;
			case '30m': return 30 * 60 * 1000;
			case '1h': return 60 * 60 * 1000;
			case '2h': return 2 * 60 * 60 * 1000;
			case '4h': return 4 * 60 * 60 * 1000;
			case '6h': return 6 * 60 * 60 * 1000;
			case '12h': return 12 * 60 * 60 * 1000;
			case '1d': return 24 * 60 * 60 * 1000;
			case '1w': return 7 * 24 * 60 * 60 * 1000;
			case '1M': return 30 * 24 * 60 * 60 * 1000; // Approximate, as month length varies
			default: return null;
		}
	}

	/**
	 * Получает свечи для указанной торговой пары и таймфрейма
	 *
	 * @param string $symbol Торговая пара (например, "BTCUSDT")
	 * @param string $interval Таймфрейм ("15" для 15 минут, "240" для 4 часов)
	 * @param string $category Категория продукта (spot, linear, inverse)
	 * @param int $limit Количество свечей (максимум 1000)
	 * @param int|null $startTime Начальная временная метка (ms)
	 * @param int|null $endTime Конечная временная метка (ms)
	 * @return Candle[]
	 */
	public function getKlines(string $symbol, string $interval, string $category, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array
	{
		try {
			$params = [
				'category' => $category,
				'symbol' => $symbol,
				'interval' => $interval,
				'limit' => $limit
			];
			if ($startTime !== null) {
				$params['start'] = $startTime;
			}
			if ($endTime !== null) {
				$params['end'] = $endTime;
			}

			$this->log("Sending getKline request to Bybit with params: " . json_encode($params));
			$response = $this->api->marketApi()->getKline($params);
			$candles = [];

			if (!isset($response['list']) || empty($response['list'])) {
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
			$this->log("Не удалось получить свечи для {$symbol} на {$this->exchangeName}: " . $exception->getMessage());
			return [];
		} catch (\Exception $e) {
			$this->log("Неожиданная ошибка при получении свечей для {$symbol} на {$this->exchangeName}: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * Получает исторические свечи для указанной торговой пары и таймфрейма за заданный период.
	 *
	 * @param string $symbol Торговая пара (например, "BTCUSDT")
	 * @param string $timeframe Таймфрейм (например, "15m", "1h")
	 * @param string $marketType Категория продукта (spot, linear, inverse)
	 * @param int $startDate Начальная временная метка (Unix, секунды)
	 * @param int $endDate Конечная временная метка (Unix, секунды)
	 * @return \Izzy\Candle[] Массив объектов Candle, отсортированных от старых к новым
	 */
	public function getHistoricalCandles(string $symbol, string $timeframe, string $marketType, int $startDate, int $endDate): array
	{
		$allCandles = [];
		$bybitInterval = $this->convertTimeframeToBybitInterval($timeframe);
		if (!$bybitInterval) {
			$this->log("Неизвестный таймфрейм {$timeframe} для Bybit.");
			return [];
		}

		$intervalMilliseconds = $this->convertTimeframeToMilliseconds($timeframe);
		if (!$intervalMilliseconds) {
			$this->log("Неизвестный таймфрейм {$timeframe} для конвертации в миллисекунды.");
			return [];
		}

		$bybitCategory = '';
		switch ($marketType) {
			case 'spot':
				$bybitCategory = 'spot';
				break;
			case 'futures':
				$bybitCategory = 'linear';
				break;
			default:
				$this->log("Неизвестный тип рынка {$marketType} для Bybit.");
				return [];
		}

		$bybitSymbol = str_replace('/', '', $symbol);
		$limit = 1000; // Максимальное количество свечей за один запрос Bybit API
		$currentEndTimeMs = $endDate * 1000; // Bybit API использует миллисекунды

		while (true) {
			$requestedStartTimeMs = max($startDate * 1000, $currentEndTimeMs - ($limit * $intervalMilliseconds));
			
			$this->log("Загрузка свечей для {$symbol} {$timeframe}. Запрашиваем с " . date('Y-m-d H:i:s', $requestedStartTimeMs / 1000) . " до " . date('Y-m-d H:i:s', $currentEndTimeMs / 1000) . ", лимит {$limit}");
			
			$candlesBatch = $this->getKlines($bybitSymbol, $bybitInterval, $bybitCategory, $limit, $requestedStartTimeMs, $currentEndTimeMs);

			if (empty($candlesBatch)) {
				// Если мы уже получили свечи и новая партия пуста, или если startDate достигнут/перейден,
				// это означает, что больше нет данных или мы достигли начала требуемого диапазона.
				if (!empty($allCandles) || $requestedStartTimeMs <= $startDate * 1000) {
					 break;
				} else {
					 // Если первая партия пуста, возможно, данных в этом диапазоне нет вообще
					 $this->log("Пустой ответ для {$symbol} {$timeframe} в диапазоне " . date('Y-m-d H:i:s', $requestedStartTimeMs / 1000) . " до " . date('Y-m-d H:i:s', $currentEndTimeMs / 1000));
					 break;
				}
			}
			
			$firstCandleTimestamp = $candlesBatch[0]->getOpenTime(); // Это самая старая свеча в этой партии
			$lastCandleTimestamp = end($candlesBatch)->getOpenTime(); // Это самая новая свеча в этой партии

			$this->log("Получено " . count($candlesBatch) . " свечей. От " . date('Y-m-d H:i:s', $firstCandleTimestamp) . " до " . date('Y-m-d H:i:s', $lastCandleTimestamp));

			// Добавляем новые свечи в начало массива
			$allCandles = array_merge($candlesBatch, $allCandles);

			// Если самая старая свеча в текущей партии старше или равна startDate, мы собрали достаточно данных
			// Или если requestedStartTimeMs уже был меньше или равен startDate * 1000
			if ($firstCandleTimestamp <= $startDate || $requestedStartTimeMs <= $startDate * 1000) {
				break;
			}

			// Устанавливаем endTime для следующего запроса на OpenTime самой старой свечи текущей партии (миллисекунды)
			$currentEndTimeMs = $firstCandleTimestamp * 1000 - 1; // Минус 1мс, чтобы не получить ту же свечу
		}

		// Фильтруем свечи, которые выходят за пределы startDate и endDate
		$filteredCandles = array_filter($allCandles, function($candle) use ($startDate, $endDate) {
			return $candle->getOpenTime() >= $startDate && $candle->getCloseTime() <= $endDate; // Ensure close time is also within endDate
		});

		// Переиндексируем массив после фильтрации
		$allCandles = array_values($filteredCandles);

		// Сортируем весь набор свечей еще раз на всякий случай
		usort($allCandles, function($a, $b) {
			return $a->getOpenTime() - $b->getOpenTime();
		});

		return $allCandles;
	}

	protected function getBalance(): float {
		return $this->totalBalance ? $this->totalBalance->getAmount() : 0.0;
	}

	protected function shouldUpdateOrders(): bool {
		// TODO: Implement shouldUpdateOrders() method.
		return false;
	}
}
