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
	/** @var int Current simulation timestamp (candle time) for position created_at. */
	private int $simulationTime = 0;

	/** @var array<string, list<array{orderId: string, price: float, volumeBase: float, direction: PositionDirectionEnum}>> Pending limit orders (grid levels) per market. */
	private array $pendingLimitOrders = [];

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
		// Also update the Market's own price cache to prevent stale reads.
		// Market::getCurrentPrice() has a 10s TTL based on wall-clock time();
		// in backtesting, multiple ticks are processed within milliseconds,
		// so the cache would never expire and would return stale prices.
		$market->setCurrentPrice($price);
	}

	/**
	 * Set current simulation time (candle open time) so new positions get correct created_at.
	 */
	public function setSimulationTime(int $timestamp): void {
		$this->simulationTime = $timestamp;
	}

	public function getSimulationTime(): int {
		return $this->simulationTime;
	}

	private function marketKey(IMarket $market): string {
		return $market->getExchangeName() . '_' . $market->getTicker() . '_' . $market->getMarketType()->value;
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
		// Do not deduct from balance: margin stays on the account and only funds the position.
		$orderId = 'bt-' . (++$this->orderIdCounter);
		$createdAt = $this->simulationTime > 0 ? $this->simulationTime : null;
		$position = BacktestStoredPosition::create(
			market: $market,
			volume: $amount,
			direction: $direction,
			entryPrice: $currentPrice,
			currentPrice: $currentPrice,
			status: PositionStatusEnum::OPEN,
			exchangePositionId: $orderId,
			createdAt: $createdAt
		);
		$position->setAverageEntryPrice($currentPrice);
		if ($takeProfitPercent !== null) {
			$position->setExpectedProfitPercent($takeProfitPercent);
			$position->setTakeProfitPrice($currentPrice->modifyByPercentWithDirection($takeProfitPercent, $direction));
		}
		$position->save();
		$this->logger->backtestProgress("  OPEN {$market->getTicker()} {$direction->value} @ " . number_format($currentPrice->getAmount(), 4) . " vol=" . number_format($amount->getAmount(), 2));
		return true;
	}

	public function buyAdditional(IMarket $market, Money $amount): bool {
		$position = $market->getStoredPosition();
		if (!$position instanceof BacktestStoredPosition) {
			return false;
		}
		// Do not deduct from balance: margin stays on the account.
		$currentPrice = $this->getCurrentPrice($market);
		if ($currentPrice) {
			$position->updateInfo($market);
		}
		$position->save();
		$this->logger->backtestProgress("  DCA {$market->getTicker()} +" . number_format($amount->getAmount(), 2) . " -> balance " . number_format($this->virtualBalance, 2) . " USDT");
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

	public static function pairToTicker(IPair $pair): string {
		return $pair->getBaseCurrency() . $pair->getQuoteCurrency();
	}

	public function getSpotBalanceByCurrency(string $coin): Money {
		return $coin === 'USDT' ? $this->getVirtualBalance() : Money::from(0.0, $coin);
	}

	public function getCurrentFuturesPosition(IMarket $market): IPositionOnExchange|false {
		$where = [
			BacktestStoredPosition::FExchangeName => $this->getName(),
			BacktestStoredPosition::FTicker => $market->getTicker(),
			BacktestStoredPosition::FMarketType => $market->getMarketType()->value,
			BacktestStoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
		];
		$position = $this->database->selectOneObject(BacktestStoredPosition::class, $where);
		if (!$position instanceof BacktestStoredPosition) {
			return false;
		}
		return new BacktestPositionOnExchange($market, $position);
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
		// Do not deduct from balance: margin stays on the account and only funds the position.
		$orderId = 'bt-' . (++$this->orderIdCounter);
		$key = $this->marketKey($market);
		// Entry order (takeProfitPercent set): Market::openPositionByDCAGrid creates the position. Do not create here.
		// Clear any stale pending orders for this market so we only have orders from the current grid.
		if ($takeProfitPercent !== null) {
			unset($this->pendingLimitOrders[$key]);
		}
		// Grid level (takeProfitPercent null): do not create a position; record as pending limit order to fill when price is reached.
		if ($takeProfitPercent === null) {
			if (!isset($this->pendingLimitOrders[$key])) {
				$this->pendingLimitOrders[$key] = [];
			}
			$this->pendingLimitOrders[$key][] = [
				'orderId' => $orderId,
				'price' => $price->getAmount(),
				'volumeBase' => $amount->getAmount(),
				'direction' => $direction,
			];
		}
		$volumeQuote = $amount->getAmount() * $price->getAmount();
		$this->logger->backtestProgress(" Placing limit order: {$market->getTicker()} {$direction->value} @ " . $price->format() . " vol=" . number_format($volumeQuote, 2) . " USDT");
		return $orderId;
	}

	/**
	 * Get pending limit orders (grid levels) for a market. When price reaches the order level, the backtester should call addToPosition and removePendingLimitOrder.
	 * @return list<array{orderId: string, price: float, volumeBase: float, direction: PositionDirectionEnum}>
	 */
	public function getPendingLimitOrders(IMarket $market): array {
		$key = $this->marketKey($market);
		return $this->pendingLimitOrders[$key] ?? [];
	}

	/**
	 * Remove a filled pending limit order.
	 */
	public function removePendingLimitOrder(IMarket $market, string $orderId): void {
		$key = $this->marketKey($market);
		if (!isset($this->pendingLimitOrders[$key])) {
			return;
		}
		$this->pendingLimitOrders[$key] = array_values(array_filter(
			$this->pendingLimitOrders[$key],
			fn($o) => $o['orderId'] !== $orderId
		));
		if ($this->pendingLimitOrders[$key] === []) {
			unset($this->pendingLimitOrders[$key]);
		}
	}

	/**
	 * Add filled grid level to the existing position (DCA): update volume and average entry, recalc TP from new average.
	 */
	public function addToPosition(IMarket $market, float $volumeBase, float $fillPrice): bool {
		$position = $market->getStoredPosition();
		if (!$position instanceof BacktestStoredPosition) {
			return false;
		}
		$oldVol = $position->getVolume()->getAmount();
		$oldEntry = $position->getAverageEntryPrice()->getAmount();
		$newVol = $oldVol + $volumeBase;
		$newAvgEntry = ($oldVol * $oldEntry + $volumeBase * $fillPrice) / $newVol;
		$baseCurrency = $market->getPair()->getBaseCurrency();
		$quoteCurrency = $market->getPair()->getQuoteCurrency();
		$position->setVolume(Money::from($newVol, $baseCurrency));
		$position->setAverageEntryPrice(Money::from($newAvgEntry, $quoteCurrency));
		$percent = $position->getExpectedProfitPercent();
		if (abs($percent) >= 0.0001) {
			$avgEntryMoney = Money::from($newAvgEntry, $quoteCurrency);
			$position->setTakeProfitPrice($avgEntryMoney->modifyByPercentWithDirection($percent, $position->getDirection()));
		}
		$position->save();
		$this->logger->backtestProgress(" DCA averaging: {$market->getTicker()} +" . number_format($volumeBase, 4) . " @ " . number_format($fillPrice, 4) . " -> vol " . number_format($newVol, 4) . " avg " . number_format($newAvgEntry, 4));
		return true;
	}

	public function removeLimitOrders(IMarket $market): bool {
		$this->clearPendingLimitOrders($market);
		return true;
	}

	/**
	 * Clear all pending limit orders for a market.
	 * Called when a position is closed to remove stale DCA orders.
	 */
	public function clearPendingLimitOrders(IMarket $market): void {
		$key = $this->marketKey($market);
		unset($this->pendingLimitOrders[$key]);
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
