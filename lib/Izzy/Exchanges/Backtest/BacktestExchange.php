<?php

namespace Izzy\Exchanges\Backtest;

use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Financial\BacktestStoredPosition;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Financial\Order;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\Interfaces\IPositionOnExchange;
use Izzy\System\Database\Database;
use Izzy\System\Logger;

/**
 * Virtual exchange driver for backtesting. Records trades to backtest_positions and tracks virtual balance.
 */
class BacktestExchange implements IExchangeDriver
{
	private Database $database;
	private Logger $logger;
	private string $name;
	private ExchangeConfiguration $config;
	private float $virtualBalance;
	/** @var array<string, Money> Current price per market key (exchange_ticker_marketType). */
	private array $currentPriceByMarketKey = [];
	private int $orderIdCounter = 0;

	public function __construct(
		Database $database,
		Logger $logger,
		string $name,
		ExchangeConfiguration $config,
		float $initialBalance = 10000.0
	) {
		$this->database = $database;
		$this->logger = $logger;
		$this->name = $name;
		$this->config = $config;
		$this->virtualBalance = $initialBalance;
	}

	public function getVirtualBalance(): Money {
		return Money::from($this->virtualBalance, 'USDT');
	}

	/**
	 * Set the current price for a market (used by the backtest runner before each step).
	 */
	public function setCurrentPriceForMarket(IMarket $market, Money $price): void {
		$this->currentPriceByMarketKey[$this->marketKey($market)] = $price;
	}

	private function marketKey(IMarket $market): string {
		return $market->getExchangeName().'_'.$market->getTicker().'_'.$market->getMarketType()->value;
	}

	public function update(): int {
		return 60;
	}

	public function updateBalance(): void {
	}

	public function connect(): bool {
		return true;
	}

	public function disconnect(): void {
	}

	public function getCurrentPrice(IMarket $market): ?Money {
		$key = $this->marketKey($market);
		return $this->currentPriceByMarketKey[$key] ?? null;
	}

	public function openPosition(IMarket $market, PositionDirectionEnum $direction, Money $amount, ?Money $price = null, ?float $takeProfitPercent = null): bool {
		$currentPrice = $price ?? $this->getCurrentPrice($market);
		if (!$currentPrice) {
			return false;
		}
		$cost = $amount->getAmount();
		if ($cost > $this->virtualBalance) {
			return false;
		}
		$this->virtualBalance -= $cost;
		$orderId = 'bt-'.(++$this->orderIdCounter);
		$position = BacktestStoredPosition::create(
			market: $market,
			volume: $amount,
			direction: $direction,
			entryPrice: $currentPrice,
			currentPrice: $currentPrice,
			status: PositionStatusEnum::OPEN,
			exchangePositionId: $orderId
		);
		$position->setAverageEntryPrice($currentPrice);
		if ($takeProfitPercent !== null) {
			$position->setExpectedProfitPercent($takeProfitPercent);
			$position->setTakeProfitPrice($currentPrice->modifyByPercentWithDirection($takeProfitPercent, $direction));
		}
		$position->save();
		return true;
	}

	public function buyAdditional(IMarket $market, Money $amount): bool {
		$position = $market->getStoredPosition();
		if (!$position instanceof BacktestStoredPosition) {
			return false;
		}
		$cost = $amount->getAmount();
		if ($cost > $this->virtualBalance) {
			return false;
		}
		$this->virtualBalance -= $cost;
		$currentPrice = $this->getCurrentPrice($market);
		if ($currentPrice) {
			$position->updateInfo($market);
		}
		$position->save();
		return true;
	}

	public function sellAdditional(IMarket $market, Money $amount): bool {
		$position = $market->getStoredPosition();
		if (!$position) {
			return false;
		}
		$position->updateInfo($market);
		$position->save();
		return true;
	}

	public function getCandles(IPair $pair, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array {
		return [];
	}

	public function createMarket(IPair $pair): ?IMarket {
		$market = new Market($this, $pair);
		$market->setPositionRecordClass(BacktestStoredPosition::class);
		return $market;
	}

	public function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency().$pair->getQuoteCurrency();
	}

	public function getSpotBalanceByCurrency(string $coin): Money {
		return $coin === 'USDT' ? $this->getVirtualBalance() : Money::from(0.0, $coin);
	}

	public function getCurrentFuturesPosition(IMarket $market): IPositionOnExchange|false {
		return false;
	}

	public function placeLimitOrder(
		IMarket $market,
		Money $amount,
		Money $price,
		PositionDirectionEnum $direction,
		?float $takeProfitPercent = null
	): string|false {
		$currentPrice = $this->getCurrentPrice($market);
		if (!$currentPrice) {
			return false;
		}
		$volumeQuote = $amount->getAmount() * $price->getAmount();
		if ($volumeQuote > $this->virtualBalance) {
			return false;
		}
		$this->virtualBalance -= $volumeQuote;
		$orderId = 'bt-'.(++$this->orderIdCounter);
		$position = BacktestStoredPosition::create(
			market: $market,
			volume: $amount,
			direction: $direction,
			entryPrice: $price,
			currentPrice: $price,
			status: PositionStatusEnum::PENDING,
			exchangePositionId: $orderId
		);
		$position->setAverageEntryPrice($price);
		if ($takeProfitPercent !== null) {
			$position->setExpectedProfitPercent($takeProfitPercent);
			$position->setTakeProfitPrice($price->modifyByPercentWithDirection($takeProfitPercent, $direction));
		}
		$position->save();
		return $orderId;
	}

	public function removeLimitOrders(IMarket $market): bool {
		return true;
	}

	public function setTakeProfit(IMarket $market, Money $expectedPrice): bool {
		return true;
	}

	public function getDatabase(): Database {
		return $this->database;
	}

	public function getLogger(): Logger {
		return $this->logger;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getExchangeConfiguration(): ExchangeConfiguration {
		return $this->config;
	}

	public function hasActiveOrder(IMarket $market, string $orderIdOnExchange): bool {
		return false;
	}

	public function getOrderById(IMarket $market, string $orderIdOnExchange): Order|false {
		return false;
	}

	public function getQtyStep(IMarket $market): string {
		return '0.0001';
	}

	public function getTickSize(IMarket $market): string {
		return '0.01';
	}

	/**
	 * Credit balance (e.g. when a position is closed at profit).
	 */
	public function creditBalance(float $amount): void {
		$this->virtualBalance += $amount;
	}
}
