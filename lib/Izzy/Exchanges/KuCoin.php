<?php

namespace Izzy\Exchanges;

use Izzy\Financial\Money;
use KuCoin\SDK\Auth;
use KuCoin\SDK\KuCoinApi;
use KuCoin\SDK\PrivateApi\Account;
use KuCoin\SDK\PublicApi\Currency;

/**
 * Драйвер для работы с биржей KuCoin
 */
class KuCoin extends AbstractExchangeDriver
{
	protected string $exchangeName = 'KuCoin';

	private ?Account $account;

	private ?Currency $currency;

	protected function refreshAccountBalance() {
		// TODO: вычислять эквивалентную стоимость других активов, не только [0]
		$accountList = $this->account->getList();
		$currencies = array_map(fn($price) => floatval($price), $this->currency->getPrices());
		$sum = array_reduce($accountList, function($carry, $item) use ($currencies) {
			$symbol = $item['currency'];
			return $carry + ($item['balance'] * $currencies[$symbol]);
		});

		$result = new Money($sum);
		//$this->log("Баланс на {$this->exchangeName}: $result");
		$this->setBalance($result);
	}

	public function connect(): bool {
		KuCoinApi::setBaseUri('https://api.kucoin.com');
		$key = $this->dbRow['key'];
		$secret = $this->dbRow['secret'];
		$password = $this->dbRow['password'];
		$auth = new Auth($key, $secret, $password, Auth::API_KEY_VERSION_V2);

		$this->account = new Account($auth);

		$this->currency = new Currency($auth);

		return true;
	}

	public function disconnect(): void {
		// TODO: Implement disconnect() method.
	}

	protected function refreshSpotOrders(): void {

	}
}
