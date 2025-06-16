<?php

namespace Izzy\Exchanges;

use GateApi\Api\SpotApi;
use GateApi\Api\WalletApi;
use GateApi\ApiException;
use GateApi\Configuration;
use Izzy\Candle;

/**
 * Драйвер для работы с биржей Gate
 */
class Gate extends AbstractExchangeDriver
{
	protected string $exchangeName = "Gate";

	private WalletApi $walletApi;

	private SpotApi $spotApi;

	protected function refreshAccountBalance(): void {
		try {
			$info = $this->walletApi->getTotalBalance(['currency' => 'USDT']);
			$value = $info->getTotal()->getAmount();
			$this->log("Баланс на {$this->exchangeName}: {$value} USDT");
		} catch (ApiException $exception) {
			$this->log("Не удалось обновить баланс кошелька на {$this->exchangeName}: " . $exception->getMessage());
		}
	}

	public function connect(): bool {
		try {
			$key = $this->config->getKey();
			$secret = $this->config->getSecret();
			$config = Configuration::getDefaultConfiguration()->setKey($key)->setSecret($secret);

			// Создадим Wallet API
			$this->walletApi = new WalletApi(null, $config);

			// Создадим Spot API
			$this->spotApi = new SpotApi(null, $config);

			return true;
		} catch (ApiException $e) {
			$this->log("Не удалось подключиться к бирже {$this->exchangeName}: " . $e->getMessage());
			return false;
		}
	}

	public function disconnect(): void {
		// TODO: Implement disconnect() method.
	}

	/**
	 * Отменить все активные спотовые ордеры по паре $pair
	 * @param string $pair пара, по которой следует удалить ордеры
	 * @return void
	 * @throws ApiException
	 */
	private function cancelOrders(string $pair): void {
		try {
			$result = $this->spotApi->cancelOrders($pair);
			var_dump($result);
		} catch (ApiException $e) {
			$this->log("Не удалось отменить ордеры для {$pair} на {$this->exchangeName}: " . $e->getMessage());
		}
	}

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
	 * Получить рынок (Market) по объекту Pair
	 * @param \Izzy\Pair $pair
	 * @return \Izzy\Market|null
	 */
	public function getMarket(\Izzy\Pair $pair): ?\Izzy\Market
	{
		$ticker = $pair->getTicker();
		$timeframe = $pair->getTimeframe();
		$marketType = $pair->getMarketType();
		$gateInterval = $this->convertTimeframeToGateInterval($timeframe);
		if (!$gateInterval) {
			$this->log("Неизвестный таймфрейм {$timeframe} для Gate.");
			return null;
		}

		// Gate API для фьючерсов и спота имеет разные конечные точки и параметры для свечей.
		// Пока что, сосредоточимся на споте, как в Bybit.
		if ($marketType !== 'spot') {
			$this->log("Графики фьючерсов для Gate пока не реализованы.");
			return null;
		}

		$symbol = str_replace('/', '_', $ticker);
		$candlesData = $this->getCandlesticks($symbol, $gateInterval, 200);
		if (empty($candlesData)) {
			return null;
		}
		
		$market = new \Izzy\Market($ticker, $timeframe, $this->exchangeName, $marketType, $candlesData);
		return $market;
	}

	private function convertTimeframeToGateInterval(string $timeframe): ?string
	{
		switch ($timeframe) {
			case '10s': return '10s';
			case '1m': return '1m';
			case '5m': return '5m';
			case '15m': return '15m';
			case '30m': return '30m';
			case '1h': return '1h';
			case '4h': return '4h';
			case '8h': return '8h';
			case '1d': return '1d';
			case '7d': return '7d';
			case '1M': return '30d';
			default: return null;
		}
	}

	/**
	 * Получает свечи для указанной торговой пары и таймфрейма с Gate.io
	 * 
	 * @param string $currencyPair Торговая пара (например, "BTC_USDT")
	 * @param string $interval Таймфрейм (например, "15m" для 15 минут)
	 * @param int $limit Количество свечей
	 * @return Candle[]
	 */
	public function getCandlesticks(string $currencyPair, string $interval, int $limit = 100): array
	{
		try {
			$params = [
				'currency_pair' => $currencyPair,
				'interval' => $interval,
				'limit' => $limit,
			];
			$response = $this->spotApi->listCandlesticks($params);
			$candles = [];
			// Gate возвращает свечи в порядке от новых к старым, поэтому нужно перевернуть массив
			$response = array_reverse($response);
			foreach ($response as $item) {
				$candles[] = new Candle(
					(int)$item->getTime(), // timestamp
					(float)$item->getOpen(), // open
					(float)$item->getHigh(), // high
					(float)$item->getLow(), // low
					(float)$item->getClose(), // close
					(float)$item->getVolume()  // volume
				);
			}
			return $candles;
		} catch (ApiException $exception) {
			$this->log("Не удалось получить свечи для {$currencyPair} на {$this->exchangeName}: " . $exception->getMessage());
			return [];
		}
	}
}
