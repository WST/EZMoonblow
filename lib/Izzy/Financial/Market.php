<?php

namespace Izzy\Financial;

use Exception;
use Izzy\Chart\Chart;
use Izzy\Enums\CandleStorageEnum;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\Backtest\BacktestExchange;
use Izzy\Indicators\IndicatorFactory;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;
use Izzy\Interfaces\IStrategy;
use Izzy\System\Database\Database;
use Izzy\System\Logger;
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
	 * Last strategy validation result (for web UI access).
	 */
	private ?StrategyValidationResult $lastValidationResult = null;

	/**
	 * Timestamp when the last validation was performed.
	 */
	private int $lastValidationTimestamp = 0;

	/**
	 * How often to re-validate exchange settings (in seconds).
	 */
	private const int VALIDATION_CACHE_TTL = 600; // 10 minutes

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
	 * @inheritDoc
	 */
	public function requestCandles(TimeFrameEnum $timeframe, int $startTime, int $endTime): ?array {
		$pair = $this->pair;

		// Build a temporary pair with the requested timeframe for the repository query.
		$queryPair = new Pair(
			$pair->getTicker(),
			$timeframe,
			$pair->getExchangeName(),
			$pair->getMarketType()
		);

		// In backtest mode, load directly from the candles table (historical data).
		// The async queue is not running during backtests, so we read synchronously
		// from the same repository that Backtester::loadCandles() writes to.
		if ($this->exchange instanceof BacktestExchange) {
			$candleRepo = new CandleRepository($this->database);
			return $candleRepo->getCandles($queryPair, $startTime, $endTime);
		}

		// Runtime mode: check the runtime_candles table first.
		$runtimeRepo = new RuntimeCandleRepository($this->database);
		$tfValue = $timeframe->value;
		$tfSeconds = $timeframe->toSeconds();

		$latestStored = $runtimeRepo->getLatestCandleTime($queryPair, $tfValue, $startTime, $endTime);

		if ($latestStored !== null) {
			// Candles exist. Check two things:
			// 1) Freshness: is the latest candle recent enough?
			// 2) Completeness: does historical data go back far enough?

			// 1) Fresh end: latest stored candle should be no more than one
			//    timeframe period behind the requested end.
			$isStale = ($latestStored + $tfSeconds < $endTime);
			if ($isStale) {
				QueueTask::loadCandles(
					$this->database,
					$pair->getExchangeName(),
					$pair->getTicker(),
					$pair->getMarketType()->value,
					$tfValue,
					$latestStored, // only fetch from the last known candle onward
					$endTime,
					CandleStorageEnum::RUNTIME,
				);
			}

			// 2) Complete start: earliest stored candle should be close to the
			//    requested start. If it is more than 2 timeframe periods away,
			//    historical data is incomplete — schedule a backfill.
			$earliestStored = $runtimeRepo->getEarliestCandleTime($queryPair, $tfValue, $startTime, $endTime);
			if ($earliestStored !== null && ($earliestStored - $startTime) > $tfSeconds * 2) {
				QueueTask::loadCandles(
					$this->database,
					$pair->getExchangeName(),
					$pair->getTicker(),
					$pair->getMarketType()->value,
					$tfValue,
					$startTime,
					$earliestStored, // only fetch the missing historical part
					CandleStorageEnum::RUNTIME,
				);
			}

			return $runtimeRepo->getCandles($queryPair, $startTime, $endTime);
		}

		// No candles at all — schedule full loading.
		QueueTask::loadCandles(
			$this->database,
			$pair->getExchangeName(),
			$pair->getTicker(),
			$pair->getMarketType()->value,
			$tfValue,
			$startTime,
			$endTime,
			CandleStorageEnum::RUNTIME,
		);

		return null;
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
	 * Get the last strategy validation result.
	 * Available after processTrading() or runValidation() has been called.
	 *
	 * @return StrategyValidationResult|null Validation result, or null if not yet validated.
	 */
	public function getValidationResult(): ?StrategyValidationResult {
		return $this->lastValidationResult;
	}

	/**
	 * Run strategy validation against exchange settings.
	 * Results are cached for VALIDATION_CACHE_TTL seconds to avoid
	 * excessive API calls to the exchange on every trading cycle.
	 *
	 * @param bool $force Force re-validation, ignoring the cache.
	 * @return StrategyValidationResult Validation result.
	 */
	public function runValidation(bool $force = false): StrategyValidationResult {
		// Return cached result if still fresh.
		if (!$force
			&& $this->lastValidationResult !== null
			&& (time() - $this->lastValidationTimestamp) < self::VALIDATION_CACHE_TTL
		) {
			return $this->lastValidationResult;
		}

		$strategy = $this->getStrategy();
		if (!$strategy) {
			$this->lastValidationResult = new StrategyValidationResult();
		} else {
			$this->lastValidationResult = $strategy->validateExchangeSettings($this);
		}
		$this->lastValidationTimestamp = time();
		return $this->lastValidationResult;
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

		// Get indicator classes from strategy.
		// Skip indicators that were already created by initializeConfiguredIndicators()
		// with user-specified parameters — otherwise the config values would be
		// silently overwritten by the strategy's default (parameterless) instance.
		$strategyIndicatorClasses = $this->strategy->useIndicators();
		foreach ($strategyIndicatorClasses as $indicatorClass) {
			$name = $indicatorClass::getName();
			if (isset($this->indicators[$name])) {
				continue;
			}
			try {
				$indicator = IndicatorFactory::create($this, $indicatorClass);
				$this->addIndicator($indicator);
			} catch (Exception $e) {
				// Log error but continue with other indicators
				error_log("Failed to initialize indicator $indicatorClass: " . $e->getMessage());
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
	 * Extract the latest values of all registered indicators as a flat
	 * associative array suitable for JSON serialization (web backtest UI).
	 *
	 * @return array<string, float|null> e.g. ['bb_upper' => 31.2, 'bb_middle' => 30.8, 'bb_lower' => 30.4, 'ema' => 30.6].
	 */
	public function getIndicatorValues(): array {
		$values = [];
		foreach ($this->indicatorResults as $name => $result) {
			if (!$result->hasValues()) {
				continue;
			}
			if ($name === 'BollingerBands') {
				$values['bb_middle'] = $result->getLatestValue();
				$signals = $result->getSignals();
				$lastSignal = !empty($signals) ? end($signals) : null;
				$values['bb_upper'] = $lastSignal['upper'] ?? null;
				$values['bb_lower'] = $lastSignal['lower'] ?? null;
			} else {
				// Generic indicator: use the indicator name in lowercase.
				$values[strtolower($name)] = $result->getLatestValue();
			}
		}
		return $values;
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
			$exchangeName = "\033[37;45m " . $this->exchange->getName() . " \033[0m";
			$marketType = "\033[37;44m " . $this->getMarketType()->name . " \033[0m";
			$ticker = "\033[37;41m " . $this->pair->getTicker() . " \033[0m";
			$timeframe = "\033[37;42m " . $this->pair->getTimeframe()->name . " \033[0m";

			return $exchangeName . $marketType . $ticker . $timeframe;
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
		if (!$success) {
			return false;
		}

		// Use getStoredPosition() directly instead of getCurrentPosition().
		// exchange->openPosition() already creates and saves a StoredPosition to DB.
		// getCurrentPosition() has a fallback to getCurrentFuturesPosition() which
		// would create a DUPLICATE position from exchange data if the DB lookup fails.
		$position = $this->getStoredPosition();
		if ($position && !Logger::getLogger()->isBacktestMode()) {
			QueueTask::addTelegramNotification_positionOpened($this, $position);
		}
		return $position;
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
			$this->exchange->getLogger()->error("Failed to set strategy $strategyName for market $this: " . $e->getMessage());
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
			// DEBUG: Log position class and table
			$this->exchange->getLogger()->debug("[DEBUG] No position found for $this, positionRecordClass: {$this->positionRecordClass}");
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

		// Validate exchange settings before trading.
		// Results are cached (see VALIDATION_CACHE_TTL), so this does not
		// hit the exchange API on every cycle.
		$previousResult = $this->lastValidationResult;
		$validation = $this->runValidation();

		// Log messages only when the result has just been refreshed
		// (i.e. it is a different object from the previous one) to
		// avoid spamming the same errors every minute.
		$isNewResult = ($validation !== $previousResult);

		if (!$validation->isValid()) {
			if ($isNewResult) {
				foreach ($validation->getErrors() as $error) {
					$this->exchange->getLogger()->error("Strategy validation failed for $this: $error");
				}
			}
			return; // Do not attempt to trade.
		}
		if ($isNewResult) {
			foreach ($validation->getWarnings() as $warning) {
				$this->exchange->getLogger()->warning("Strategy warning for $this: $warning");
			}
		}

		// Is trading enabled for this Pair?
		if (!$this->pair->isTradingEnabled()) {
			$this->exchange->getLogger()->info("Trading is disabled for $this");
			// Pair is monitored but not traded — notify user about potential position entry.
			if ($this->pair->isNotificationsEnabled()) {
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
				$this->getExchange()->getLogger()->error("Failed to add indicator $indicatorType to market $this: " . $e->getMessage());
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
		if (
			$cached && $this->cachedPrice !== null
			&& ($now - $this->cachedPriceTimestamp) < self::PRICE_CACHE_TTL
		) {
			return $this->cachedPrice;
		}

		// Fetch fresh price from exchange and update cache
		$price = $this->getExchange()->getCurrentPrice($this);
		$this->cachedPrice = $price;
		$this->cachedPriceTimestamp = $now;

		return $price;
	}

	/**
	 * Force-set the current price and refresh the cache.
	 *
	 * Used by BacktestExchange to inject the simulated tick price directly
	 * into the Market's cache, bypassing the TTL check that relies on
	 * wall-clock time(). Without this, rapid backtest ticks may return a
	 * stale cached price from a previous tick, causing non-deterministic results.
	 */
	public function setCurrentPrice(Money $price): void {
		$this->cachedPrice = $price;
		$this->cachedPriceTimestamp = time();
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

		if ($grid->isAlwaysMarketEntry()) {
			// Market entry: execute the entry order immediately at the current market price.
			// The position starts in OPEN status right away, so there is no risk of the
			// entry being missed due to price movement. DCA averaging levels are still
			// placed as regular limit orders below.

			// Clear any stale limit orders from a previous grid before placing new ones.
			$this->removeLimitOrders();

			// Pass volume in quote currency (USDT). Bybit::openPosition expects quote
			// currency and converts to base internally. Do NOT pre-convert here, otherwise
			// the amount will be divided by price twice, inflating the position size.
			$entryVolumeQuote = Money::from($entryLevel['volume']);
			$success = $this->exchange->openPosition($this, $direction, $entryVolumeQuote, null, $takeProfitPercent);
			if (!$success) {
				return false;
			}

			// Place remaining DCA levels as limit orders for averaging.
			foreach ($orderMap as $level) {
				$orderPrice = $entryPrice->modifyByPercent($level['offset']);
				$orderVolume = $this->calculateQuantity(Money::from($level['volume']), $orderPrice);
				$this->placeLimitOrder($orderVolume, $orderPrice, $direction);
			}

			// The position was already created and saved by exchange->openPosition().
			// Use getStoredPosition() to avoid duplicate creation via exchange fallback.
			$position = $this->getStoredPosition();
			if ($position && !Logger::getLogger()->isBacktestMode()) {
				QueueTask::addTelegramNotification_positionOpened($this, $position);
			}
			return $position;
		}

		// Limit entry: the entry order is placed as a limit order at the current price.
		// placeLimitOrder expects volume in base currency, so we convert here.
		$entryVolume = $this->calculateQuantity(Money::from($entryLevel['volume']), $entryPrice);

		// The position starts in PENDING status and transitions to OPEN when the
		// exchange fills the entry order.
		$orderIdOnExchange = $this->placeLimitOrder($entryVolume, $entryPrice, $direction, $takeProfitPercent);

		// Grid offset sign: negative = below entry (LONG averaging), positive = above entry (SHORT averaging).
		// Do not use modifyByPercentWithDirection (that is for TP: LONG up, SHORT down).
		foreach ($orderMap as $level) {
			$orderPrice = $entryPrice->modifyByPercent($level['offset']);
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

		$position->setExpectedProfitPercent($takeProfitPercent);
		$position->setTakeProfitPrice($entryPrice->modifyByPercentWithDirection($takeProfitPercent, $direction));
		$position->setAverageEntryPrice($entryPrice);

		// For backtest: use simulation time instead of wallclock time.
		if ($this->exchange instanceof BacktestExchange) {
			$simTime = $this->exchange->getSimulationTime();
			if ($simTime > 0) {
				$position->setCreatedAt($simTime);
				$position->setUpdatedAt($simTime);
			}
		}

		$position->save();

		// Notify about the new position (works for all strategies).
		if (!Logger::getLogger()->isBacktestMode()) {
			QueueTask::addTelegramNotification_positionOpened($this, $position);
		}

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

	/**
	 * Set stop-loss price.
	 *
	 * @param Money $expectedSLPrice Expected stop-loss price.
	 * @return bool True if successful, false otherwise.
	 */
	public function setStopLoss(Money $expectedSLPrice): bool {
		$this->exchange->getLogger()->debug("Updating SL on $this, setting to $expectedSLPrice");
		return $this->exchange->setStopLoss($this, $expectedSLPrice);
	}

	/**
	 * Partially close an open position.
	 *
	 * @param Money $volume Volume to close (in base currency).
	 * @param bool $isBreakevenLock Whether this partial close is part of a Breakeven Lock operation.
	 * @param Money|null $closePrice Price for PnL calculation in backtesting (see IExchangeDriver).
	 * @return bool True if successful, false otherwise.
	 */
	public function partialClose(Money $volume, bool $isBreakevenLock = false, ?Money $closePrice = null): bool {
		$this->exchange->getLogger()->debug("Partial close on $this, volume $volume");
		return $this->exchange->partialClose($this, $volume, $isBreakevenLock, $closePrice);
	}
}
