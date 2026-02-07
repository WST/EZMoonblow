<?php

namespace Izzy\Financial;

use Exception;
use Izzy\Chart\Chart;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Indicators\IndicatorFactory;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;
use Izzy\Interfaces\IStrategy;
use Izzy\Strategies\DCAOrderGrid;
use Izzy\Strategies\StrategyFactory;
use Izzy\System\Database\Database;
use Izzy\System\QueueTask;
use Izzy\Traits\HasMarketTypeTrait;

class Market implements IMarket
{
	use HasMarketTypeTrait;

	/**
	 * Active pair.
	 */
	private Pair $pair;

	/**
	 * The relevant exchange driver.
	 */
	private IExchangeDriver $exchange;

	/**
	 * Set of candles.
	 * @var ICandle[]
	 */
	private array $candles;

	/**
	 * Registered indicators for this market.
	 * @var IIndicator[]
	 */
	private array $indicators = [];

	/**
	 * Calculated indicator results.
	 * @var IndicatorResult[]
	 */
	private array $indicatorResults = [];

	/**
	 * Active strategy for this market.
	 */
	private ?IStrategy $strategy = null;

	/**
	 * Link with the database.
	 * @var Database
	 */
	private Database $database;

	/**
	 * Class name for stored position records (StoredPosition or BacktestStoredPosition).
	 * @var string
	 */
	protected string $positionRecordClass = StoredPosition::class;

	/**
	 * Cached current price.
	 */
	private ?Money $cachedPrice = null;

	/**
	 * Timestamp when price was cached.
	 */
	private ?int $cachedPriceTimestamp = null;

	/**
	 * Price cache TTL in seconds.
	 */
	private const int PRICE_CACHE_TTL = 10;

	public function __construct(
		IExchangeDriver $exchange,
		Pair $pair,
	) {
		$this->marketType = $pair->getMarketType();
		$this->exchange = $exchange;
		$this->pair = $pair;
		$this->database = $exchange->getDatabase();
	}

	/**
	 * @return ICandle[]
	 */
	public function getCandles(): array {
		return $this->candles;
	}

	/**
	 * Get the first candle.
	 *
	 * @return ICandle First candle.
	 */
	public function firstCandle(): ICandle {
		return reset($this->candles);
	}

	/**
	 * Get the last candle.
	 *
	 * @return ICandle Last candle.
	 */
	public function lastCandle(): ICandle {
		return end($this->candles);
	}

	/**
	 * Get the trading pair ticker.
	 *
	 * @return string Trading pair ticker.
	 */
	public function getTicker(): string {
		return $this->pair->getTicker();
	}

	/**
	 * Get the timeframe for this market.
	 *
	 * @return TimeFrameEnum Market timeframe.
	 */
	public function getTimeframe(): TimeFrameEnum {
		return $this->pair->getTimeframe();
	}

	/**
	 * Get the exchange driver.
	 *
	 * @return IExchangeDriver Exchange driver instance.
	 */
	public function getExchange(): IExchangeDriver {
		return $this->exchange;
	}

	/**
	 * Get the market type.
	 *
	 * @return MarketTypeEnum Market type.
	 */
	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}

	/**
	 * Get the minimum price from all candles.
	 *
	 * @return float Minimum price.
	 */
	public function getMinPrice(): float {
		if (empty($this->candles)) {
			return 0.0;
		}
		return array_reduce($this->candles, function ($min, $candle) {
			return min($min, $candle->getLowPrice());
		}, PHP_FLOAT_MAX);
	}

	/**
	 * Get the maximum price from all candles.
	 *
	 * @return float Maximum price.
	 */
	public function getMaxPrice(): float {
		if (empty($this->candles)) {
			return 0.0;
		}
		return array_reduce($this->candles, function ($max, $candle) {
			return max($max, $candle->getHighPrice());
		}, PHP_FLOAT_MIN);
	}

	/**
	 * Get the price range (max - min).
	 *
	 * @return float Price range.
	 */
	public function getPriceRange(): float {
		return $this->getMaxPrice() - $this->getMinPrice();
	}

	/**
	 * Get active strategy for this market.
	 *
	 * @return IStrategy|null Active strategy or null if not set.
	 */
	public function getStrategy(): ?IStrategy {
		return $this->strategy;
	}

	/**
	 * Initialize indicators from strategy configuration.
	 *
	 * @return void
	 */
	private function initializeStrategyIndicators(): void {
		if (!$this->strategy) {
			return;
		}

		// Get indicator classes from strategy
		$strategyIndicatorClasses = $this->strategy->useIndicators();
		foreach ($strategyIndicatorClasses as $indicatorClass) {
			try {
				$indicator = IndicatorFactory::create($this, $indicatorClass);
				$this->addIndicator($indicator);
			} catch (Exception $e) {
				// Log error but continue with other indicators
				error_log("Failed to initialize indicator $indicatorClass: ".$e->getMessage());
			}
		}
	}

	/**
	 * Set candles data for this market.
	 *
	 * @param array $candlesData Array of candle data.
	 * @return void
	 */
	public function setCandles(array $candlesData): void {
		$this->candles = $candlesData;

		// Set the current market for each candle.
		foreach ($this->candles as $candle) {
			$candle->setMarket($this);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPosition(): IStoredPosition|false {
		if ($this->getMarketType()->isSpot()) {
			// For a spot market, we emulate the positions by using a database.
			$storedPosition = $this->getStoredPosition();
			if (!$storedPosition)
				return false;
			return $storedPosition;
		}

		if ($this->getMarketType()->isFutures()) {
			// For a futures market.
			$storedPosition = $this->getStoredPosition();
			if ($storedPosition) {
				$this->exchange->getLogger()->debug("Found a stored position for $this");
				return $storedPosition;
			} else {
				$positionFromExchange = $this->exchange->getCurrentFuturesPosition($this);
				if ($positionFromExchange) {
					// NOTE: we don’t have info about entry order here.
					$this->exchange->getLogger()->debug("Found position on the exchange for $this, creating a stored position");
					return $positionFromExchange->store();
				}
			}
		}

		return false;
	}

	/**
	 * Initialize all indicators for this market.
	 *
	 * @return void
	 */
	public function initializeIndicators(): void {
		// Initialize indicators from configuration first, so they handle their settings.
		$this->initializeConfiguredIndicators();

		// Initialize the indicators required by the strategy using the default settings.
		$this->initializeStrategyIndicators();
	}

	/**
	 * Draw the candlestick chart for this Market.
	 * @return string filename
	 */
	public function drawChart(): string {
		// Initialize indicators from configuration
		$this->initializeIndicators();

		// Calculate indicator values
		$this->calculateIndicators();

		$filename = $this->pair->getChartFilename();
		$chart = new Chart($this);
		$chart->draw();
		$chart->save($filename);
		return $filename;
	}

	/**
	 * Schedule a task for updating the candlestick chart for this Market.
	 * @return void
	 */
	public function updateChart(): void {
		QueueTask::updateChart($this);
	}

	/**
	 * Get the trading pair.
	 *
	 * @return Pair Trading pair instance.
	 */
	public function getPair(): Pair {
		return $this->pair;
	}

	/**
	 * Add indicator to the market.
	 *
	 * @param IIndicator $indicator Indicator instance.
	 * @return void
	 */
	public function addIndicator(IIndicator $indicator): void {
		$this->indicators[$indicator::class::getName()] = $indicator;
	}

	/**
	 * Remove indicator from the market.
	 *
	 * @param string $indicatorName Indicator name.
	 * @return void
	 */
	public function removeIndicator(string $indicatorName): void {
		unset($this->indicators[$indicatorName]);
		unset($this->indicatorResults[$indicatorName]);
	}

	/**
	 * Get all registered indicators.
	 *
	 * @return IIndicator[] Array of indicators.
	 */
	public function getIndicators(): array {
		return $this->indicators;
	}

	/**
	 * Check if indicator is registered.
	 *
	 * @param string $indicatorName Indicator name.
	 * @return bool True if registered, false otherwise.
	 */
	public function hasIndicator(string $indicatorName): bool {
		return isset($this->indicators[$indicatorName]);
	}

	/**
	 * Calculate all indicators.
	 *
	 * @return void
	 */
	public function calculateIndicators(): void {
		foreach ($this->indicators as $name => $indicator) {
			$this->indicatorResults[$name] = $indicator->calculate($this);
		}
	}

	/**
	 * Get indicator result.
	 *
	 * @param string $indicatorName Indicator name.
	 * @return IndicatorResult|null Indicator result or null if not found.
	 */
	public function getIndicatorResult(string $indicatorName): ?IndicatorResult {
		return $this->indicatorResults[$indicatorName] ?? null;
	}

	/**
	 * Get all indicator results.
	 *
	 * @return IndicatorResult[] Array of indicator results.
	 */
	public function getAllIndicatorResults(): array {
		return $this->indicatorResults;
	}

	/**
	 * Get latest indicator value.
	 *
	 * @param string $indicatorName Indicator name.
	 * @return float|null Latest value or null if not found.
	 */
	public function getLatestIndicatorValue(string $indicatorName): ?float {
		$result = $this->getIndicatorResult($indicatorName);
		return $result?->getLatestValue();
	}

	/**
	 * Get latest indicator signal.
	 *
	 * @param string $indicatorName Indicator name.
	 * @return mixed Latest signal or null if not found.
	 */
	public function getLatestIndicatorSignal(string $indicatorName): mixed {
		$result = $this->getIndicatorResult($indicatorName);
		return $result?->getLatestSignal();
	}

	/**
	 * Get market text description for the console output.
	 * TODO: web representation (HTML)
	 * @param bool $consoleColors
	 * @return string
	 */
	public function getDescription(bool $consoleColors = true): string {
		if ($consoleColors) {
			$exchangeName = "\033[37;45m ".$this->exchange->getName()." \033[0m";
			$marketType = "\033[37;44m ".$this->getMarketType()->name." \033[0m";
			$ticker = "\033[37;41m ".$this->pair->getTicker()." \033[0m";
			$timeframe = "\033[37;42m ".$this->pair->getTimeframe()->name." \033[0m";

			return $exchangeName.$marketType.$ticker.$timeframe;
		} else {
			$format = "%s, %s, %s, %s";
			$args = [
				$this->getExchange()->getName(),
				$this->getMarketType()->name,
				$this->pair->getTicker(),
				$this->pair->getTimeframe()->name
			];

			return sprintf($format, ...$args);
		}
	}

	/**
	 * Convert market to string representation.
	 * @return string
	 */
	public function __toString(): string {
		return $this->getDescription();
	}

	/**
	 * Open a new position.
	 *
	 * @param Money $volume Position volume.
	 * @param PositionDirectionEnum $direction Position direction.
	 * @param float $takeProfitPercent Take profit percentage.
	 * @return IStoredPosition|false Created position or false on failure.
	 */
	public function openPosition(Money $volume, PositionDirectionEnum $direction, float $takeProfitPercent): IStoredPosition|false {
		$success = $this->exchange->openPosition($this, $direction, $volume, null, $takeProfitPercent);
		return $success ? $this->getCurrentPosition() : false;
	}

	/**
	 * Get exchange name.
	 *
	 * @return string Exchange name.
	 */
	public function getExchangeName(): string {
		return $this->exchange->getName();
	}

	/**
	 * Get database instance.
	 *
	 * @return Database Database instance.
	 */
	public function getDatabase(): Database {
		return $this->database;
	}

	/**
	 * Get a stored position for this market.
	 * @return IStoredPosition|false Position data or false if not found.
	 */
	public function getStoredPosition(): IStoredPosition|false {
		$where = [
			StoredPosition::FExchangeName => $this->getExchangeName(),
			StoredPosition::FTicker => $this->getTicker(),
			StoredPosition::FMarketType => $this->getMarketType()->value,
			StoredPosition::FStatus => [PositionStatusEnum::PENDING->value, PositionStatusEnum::OPEN->value],
		];
		return $this->database->selectOneObject($this->positionRecordClass, $where, $this);
	}

	/**
	 * Set the class to use for loading/saving stored positions (e.g. for backtesting).
	 *
	 * @param string $class Fully qualified class name (must extend StoredPosition).
	 */
	public function setPositionRecordClass(string $class): void {
		$this->positionRecordClass = $class;
	}

	/**
	 * Setup strategy for this market based on configuration.
	 * @return void
	 */
	public function initializeStrategy(): void {
		// Skip if strategy is already set.
		if ($this->getStrategy()) {
			return;
		}

		$pair = $this->getPair();
		$strategyName = $pair->getStrategyName();
		if (empty($strategyName)) {
			return;
		}

		try {
			$strategyParams = $pair->getStrategyParams();
			$strategy = StrategyFactory::create($this, $strategyName, $strategyParams);
			$this->strategy = $strategy;
			$this->exchange->getLogger()->info("Set strategy $strategyName for market $this");
		} catch (Exception $e) {
			$this->exchange->getLogger()->error("Failed to set strategy $strategyName for market $this: ".$e->getMessage());
		}
	}

	/**
	 * Perform trading routines.
	 * @return void
	 */
	public function processTrading(): void {
		// Do we have a Strategy?
		$strategy = $this->getStrategy();
		if (!$strategy) {
			return;
		}

		// Do we already have an open position?
		$currentPosition = $this->getCurrentPosition();
		if ($currentPosition) {
			// If position is open, update it (check for DCA, etc.)
			$this->updatePosition($currentPosition);
		} else {
			$this->checkEntrySignals();
		}
	}

	/**
	 * Check for entry signals (shouldLong, shouldShort).
	 * Executed only if there is no active position yet.
	 * @return void
	 */
	protected function checkEntrySignals(): void {
		$strategy = $this->getStrategy();
		if (!$strategy) {
			return;
		}

		// Is trading enabled for this Pair?
		if (!$this->pair->isTradingEnabled()) {
			// Process trading signals.
			$this->exchange->getLogger()->info("Trading is disabled for $this");
			if ($this->pair->isMonitoringEnabled()) {
				// We notify the user about our intent to open a position only if trading is disabled.
				$this->sendNewPositionIntentNotifications();
			}
			return;
		}

		// Check for long entry signal
		if ($strategy->shouldLong()) {
			$this->exchange->getLogger()->info("Long signal detected for $this.");
			$this->executeLongEntry();
			return;
		}

		// Check for short entry signal (only for futures)
		if ($this->isFutures() && $strategy->shouldShort()) {
			$this->exchange->getLogger()->info("Short signal detected for $this.");
			$this->executeShortEntry();
			return;
		}
	}

	/**
	 * Execute long entry order.
	 * @return void
	 */
	protected function executeLongEntry(): void {
		$this->exchange->getLogger()->info("Long entry detected for $this.");
		$this->getStrategy()->handleLong($this);
	}

	/**
	 * Execute short entry order.
	 * @return void
	 */
	protected function executeShortEntry(): void {
		$this->exchange->getLogger()->info("Short entry detected for $this");
		$this->getStrategy()->handleShort($this);
	}

	/**
	 * Calculate quantity based on amount and price.
	 * @param Money $amount Amount of the quote currency.
	 * @param Money $price Price per unit.
	 * @return Money Quantity of the base currency.
	 */
	public function calculateQuantity(Money $amount, Money $price): Money {
		return Money::from($amount->getAmount() / $price->getAmount());
	}

	/**
	 * Setup indicators for a specific market based on configuration.
	 * @return void
	 */
	public function initializeConfiguredIndicators(): void {
		// Skip if indicators are already set up.
		if (!empty($this->getIndicators())) {
			return;
		}

		// Get indicators configuration for this pair.
		$indicatorsConfig = $this->getExchange()->getExchangeConfiguration()->getIndicatorsConfig($this);
		if (empty($indicatorsConfig)) {
			return;
		}

		// Create and add indicators.
		foreach ($indicatorsConfig as $indicatorType => $parameters) {
			try {
				$indicator = IndicatorFactory::create($this, $indicatorType, $parameters);
				$this->addIndicator($indicator);
				$this->getExchange()->getLogger()->info("Added indicator $indicatorType to market $this");
			} catch (Exception $e) {
				$this->getExchange()->getLogger()->error("Failed to add indicator $indicatorType to market $this: ".$e->getMessage());
			}
		}
	}

	/**
	 * Get current market price.
	 *
	 * @return Money Current price.
	 */
	/**
	 * Get the current price of the market.
	 *
	 * @param bool $cached If true, returns cached price if available and not expired.
	 * @return Money Current price.
	 */
	public function getCurrentPrice(bool $cached = true): Money {
		$now = time();

		// Return cached price if valid
		if ($cached && $this->cachedPrice !== null
			&& ($now - $this->cachedPriceTimestamp) < self::PRICE_CACHE_TTL) {
			return $this->cachedPrice;
		}

		// Fetch fresh price from exchange and update cache
		$price = $this->getExchange()->getCurrentPrice($this);
		$this->cachedPrice = $price;
		$this->cachedPriceTimestamp = $now;

		return $price;
	}

	/**
	 * Get the current trading context for volume calculations.
	 *
	 * This method provides runtime context (balance, margin, price) needed
	 * for resolving dynamic volume modes like percentage of balance.
	 * Uses cached price to avoid excessive API calls.
	 *
	 * @return TradingContext
	 */
	public function getTradingContext(): TradingContext {
		$balance = method_exists($this->exchange, 'getVirtualBalance')
			? $this->exchange->getVirtualBalance()->getAmount()
			: $this->getDatabase()->getTotalBalance()->getAmount();
		// For margin, we use balance with 1x leverage for now.
		// This can be enhanced later to get actual available margin from exchange.
		$margin = $balance;
		$currentPrice = $this->getCurrentPrice();

		return new TradingContext($balance, $margin, $currentPrice);
	}

	/**
	 * Update position information.
	 * Called only on an existent and active position.
	 *
	 * @param IStoredPosition $currentPosition Current position to update.
	 * @return void
	 */
	private function updatePosition(IStoredPosition $currentPosition): void {
		if (!method_exists($this->strategy, 'updatePosition')) {
			return;
		}

		// Update position info from the Exchange.
		$currentPosition->updateInfo($this);

		// All checks passed, we can ask the Strategy to update our active Position.
		$this->strategy->updatePosition($currentPosition);
		$currentPosition->save();
	}

	/**
	 * Check if order exists on exchange.
	 *
	 * @param string $orderIdOnExchange Order ID on exchange.
	 * @return bool True if order exists, false otherwise.
	 */
	public function hasOrder(string $orderIdOnExchange): bool {
		return $this->exchange->hasActiveOrder($this, $orderIdOnExchange);
	}

	/**
	 * Send notifications about new position intent.
	 *
	 * @return void
	 */
	private function sendNewPositionIntentNotifications(): void {
		// Check for long entry signal.
		if ($this->strategy->shouldLong()) {
			QueueTask::addTelegramNotification_newPosition($this, PositionDirectionEnum::LONG);
			return;
		}

		// Check for short entry signal (only for futures).
		if ($this->isFutures() && $this->strategy->shouldShort()) {
			QueueTask::addTelegramNotification_newPosition($this, PositionDirectionEnum::SHORT);
			return;
		}
	}

	/**
	 * Get the task attributes for this Market.
	 * @return array
	 */
	public function getTaskMarketAttributes(): array {
		return [
			'pair' => $this->getPair()->getTicker(),
			'timeframe' => $this->getPair()->getTimeframe()->value,
			'marketType' => $this->getPair()->getMarketType()->value,
			'exchange' => $this->getExchange()->getName(),
		];
	}

	/**
	 * Check if the given task’s market attributes match this Market’s attributes.
	 * @param array $taskAttributes
	 * @return bool
	 */
	public function taskMarketAttributesMatch(array $taskAttributes): bool {
		$currentAttributes = $this->getTaskMarketAttributes();
		foreach ($currentAttributes as $attribute => $value) {
			// We don’t check the extra attributes, we only compare Market attributes instead.
			if (!isset($taskAttributes[$attribute]))
				continue;
			if ($taskAttributes[$attribute] !== $value)
				return false;
		}
		return true;
	}

	/**
	 * Place a limit order.
	 *
	 * @param Money $volume Volume in USDT (quote currency).
	 * @param Money $price Order price.
	 * @param PositionDirectionEnum $direction Order direction.
	 * @param float|null $takeProfitPercent Take profit percentage.
	 * @return string|false Order ID or false on failure.
	 */
	public function placeLimitOrder(Money $volume, Money $price, PositionDirectionEnum $direction, ?float $takeProfitPercent = null): string|false {
		return $this->exchange->placeLimitOrder($this, $volume, $price, $direction, $takeProfitPercent);
	}

	/**
	 * Open position using DCA order grid.
	 *
	 * @param DCAOrderGrid $grid DCA order grid with levels, direction and TP percent.
	 * @return IStoredPosition|false Created position or false on failure.
	 */
	public function openPositionByDCAGrid(DCAOrderGrid $grid): IStoredPosition|false {
		$context = $this->getTradingContext();
		$direction = $grid->getDirection();
		$takeProfitPercent = $grid->getExpectedProfit();
		$orderMap = $grid->buildOrderMap($context);

		$entryLevel = array_shift($orderMap);
		$entryPrice = $this->getCurrentPrice();
		$entryVolume = $this->calculateQuantity(Money::from($entryLevel['volume']), $entryPrice);

		/**
		 * This is the entry order.
		 */
		$orderIdOnExchange = $this->placeLimitOrder($entryVolume, $entryPrice, $direction, $takeProfitPercent);

		foreach ($orderMap as $level) {
			$orderPrice = $entryPrice->modifyByPercentWithDirection(abs($level['offset']), $direction);
			$orderVolume = $this->calculateQuantity(Money::from($level['volume']), $orderPrice);
			$this->placeLimitOrder($orderVolume, $orderPrice, $direction);
		}

		/**
		 * Position to be saved into the database.
		 */
		$positionClass = $this->positionRecordClass;
		$position = $positionClass::create(
			market: $this,
			volume: $entryVolume,
			direction: $direction,
			entryPrice: $entryPrice,
			currentPrice: $entryPrice,
			status: PositionStatusEnum::PENDING,
			exchangePositionId: $orderIdOnExchange
		);

		/**
		 * Some extra financial info for further calculations.
		 */
		$position->setExpectedProfitPercent($takeProfitPercent);
		$position->setTakeProfitPrice($entryPrice->modifyByPercentWithDirection($takeProfitPercent, $direction));
		$position->setAverageEntryPrice($entryPrice);

		// Save the position.
		$position->save();

		return $position;
	}

	/**
	 * Remove all limit orders for this market.
	 *
	 * @return bool True if successful, false otherwise.
	 */
	public function removeLimitOrders(): bool {
		return $this->exchange->removeLimitOrders($this);
	}

	/**
	 * Set take profit price.
	 *
	 * @param Money $expectedTPPrice Expected take profit price.
	 * @return bool True if successful, false otherwise.
	 */
	public function setTakeProfit(Money $expectedTPPrice): bool {
		$this->exchange->getLogger()->debug("Updating TP on $this, setting to $expectedTPPrice");
		return $this->exchange->setTakeProfit($this, $expectedTPPrice);
	}
}
