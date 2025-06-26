<?php

namespace Izzy\Exchanges;

use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPosition;
use KuCoin\SDK\Auth;
use KuCoin\SDK\KuCoinApi;
use KuCoin\SDK\PrivateApi\Account;
use KuCoin\SDK\PublicApi\Currency;

/**
 * KuCoin exchange driver.
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
		$this->saveBalance($result);
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

	public function updateBalance(): void {
		// TODO: Implement updateBalance() method.
	}

	public function getCurrentPosition(IPair $pair): ?IPosition {
		// TODO: Implement getCurrentPosition() method.
	}

	public function getCurrentPrice(IPair $pair): ?float {
		// TODO: Implement getCurrentPrice() method.
	}

	public function openLong(IPair $pair, Money $amount, ?float $price = null): bool {
		// TODO: Implement openLong() method.
	}

	public function openShort(IPair $pair, Money $amount, ?float $price = null): bool {
		// TODO: Implement openShort() method.
	}

	public function closePosition(IPair $pair, ?float $price = null): bool {
		// TODO: Implement closePosition() method.
	}

	public function buyAdditional(IPair $pair, Money $amount): bool {
		// TODO: Implement buyAdditional() method.
	}

	public function sellAdditional(IPair $pair, Money $amount): bool {
		// TODO: Implement sellAdditional() method.
	}

	public function getCandles(IPair $pair, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array {
		// TODO: Implement getCandles() method.
	}

	public function createMarket(IPair $pair): ?IMarket {
		// TODO: Implement getMarket() method.
	}

	public function pairToTicker(IPair $pair): string {
		// TODO: Implement pairToTicker() method.
	}
}
