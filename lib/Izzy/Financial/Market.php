<?php

namespace Izzy\Financial;

use Exception;
use Izzy\Chart\Chart;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Indicators\IndicatorFactory;
use Izzy\Interfaces\ICandle;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IStrategy;
use Izzy\Strategies\StrategyFactory;
use Izzy\System\Database\Database;
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
	 * Indicator factory for creating indicators.
	 */
	private ?IndicatorFactory $indicatorFactory = null;

	/**
	 * Link with the database.
	 * @var Database 
	 */
	private Database $database;

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

	public function firstCandle(): ICandle {
		return reset($this->candles);
	}

	public function lastCandle(): ICandle {
		return end($this->candles);
	}

	public function getTicker(): string {
		return $this->pair->getTicker();
	}

	public function getTimeframe(): TimeFrameEnum {
		return $this->pair->getTimeframe();
	}

	public function getExchange(): IExchangeDriver {
		return $this->exchange;
	}

	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}

	public function getMinPrice(): float {
		if (empty($this->candles)) {
			return 0.0;
		}
		return array_reduce($this->candles, function ($min, $candle) {
			return min($min, $candle->getLowPrice());
		}, PHP_FLOAT_MAX);
	}

	public function getMaxPrice(): float {
		if (empty($this->candles)) {
			return 0.0;
		}
		return array_reduce($this->candles, function ($max, $candle) {
			return max($max, $candle->getHighPrice());
		}, PHP_FLOAT_MIN);
	}

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
		$indicatorClasses = $this->strategy->useIndicators();
		
		foreach ($indicatorClasses as $indicatorClass) {
			try {
				$indicator = IndicatorFactory::create($this, $indicatorClass);
				$this->addIndicator($indicator);
			} catch (Exception $e) {
				// Log error but continue with other indicators
				error_log("Failed to initialize indicator {$indicatorClass}: " . $e->getMessage());
			}
		}
	}

	public function drawChart(TimeFrameEnum $timeframe): Chart {
		$chart = new Chart($this, $timeframe);
		$chart->draw();
		return $chart;
	}

	public function setCandles(array $candlesData): void {
		$this->candles = $candlesData;

		// Устанавливаем текущий рынок для каждой свечи
		foreach ($this->candles as $candle) {
			$candle->setMarket($this);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentPosition(): IPosition|false {
		// First, determine the pair we are trading.
		$pair = $this->getPair();
		
		// Now, get the proper ticker in the format appropriate for the current exchange.
		$ticker = $pair->getExchangeTicker($this->exchange);

		/* FOR SPOT
		 * 1. Fetch the position from the database.
		 * 2. Check the position status recorded in the database
		 * --------------------------------------------------------------------------------------------
		 * 3. If the status is pending, check the presence of a “buy” limit order on the exchange.
		 * 4. If the order exists, the position is still pending, we update price info and return the position.
		 * 5. If the order does not exist, we check the balance of the base currency on the exchange.
		 * 6. If the balance of the base currency on the exchange is greater or equals stored, we
		 *    set the position status to “open”, update price info and return the position.
		 * --------------------------------------------------------------------------------------------
		 * 7. If the status is open and the balance of the base currency is less than stored,
		 *    we set the position status to “finished”, update the information and return the position.
		 * 8. If the status is open and the balance is greater or equals stored, we update the price info
		 *    and return the position.
		 * --------------------------------------------------------------------------------------------
		 * 9. If the status is finished, we return false.
		 */
		
		if ($this->getMarketType()->isSpot()) {
			// Fetching the position info from the database.
			$storedPosition = $this->getStoredPosition();
			if (!$storedPosition) return false;
			
			// Get the position status recorded in the database.
			$storedStatus = $storedPosition->getStatus();

			// If the status is pending, check the presence of a “buy” limit order on the exchange.
			if ($storedStatus->isPending()) {

			}
			
			return $storedPosition;
		} else {
			return false;
		}
	}

	public function updateChart(): void {
		$filename = $this->pair->getChartFilename();
		$chart = new Chart($this);
		$chart->draw();
		$chart->save($filename);
	}

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

	public function openLongPosition(Money $volume): IPosition|false {
		$success = $this->exchange->openLong($this, $volume);
		return $this->getCurrentPosition();
	}

	public function openShortPosition(Money $volume): IPosition|false {
		$success = $this->exchange->openShort($this, $volume);
		return $this->getCurrentPosition();
	}

	/**
	 * Check if this Market has an active position.
	 * By “active” we mean open or pending.
	 * @return bool
	 */
	public function hasActivePosition(): bool {
		$currentPosition = $this->getCurrentPosition();
		return $currentPosition && $currentPosition->isActive();
	}
	
	public function getExchangeName(): string {
		return $this->exchange->getName();
	}

	public function getDatabase(): Database {
		return $this->database;
	}

	/**
	 * Get a stored position for this market.
	 * @return IPosition|false Position data or false if not found.
	 */
	public function getStoredPosition(): IPosition|false {
		$where = [
			'position_exchange_name' =>  $this->getExchangeName(),
			'position_ticker' => $this->getTicker(),
			'position_market_type' => $this->getMarketType()->value,
		];
		return $this->database->selectOneObject(Position::class, $where, $this);
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
			$strategy = StrategyFactory::create($strategyName, $this, $strategyParams);
			$this->strategy = $strategy;
			$this->exchange->getLogger()->info("Set strategy $strategyName for market $this");
		} catch (Exception $e) {
			$this->exchange->getLogger()->error("Failed to set strategy $strategyName for market $this: " . $e->getMessage());
		}
		$this->initializeStrategyIndicators();
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
		if ($currentPosition && $currentPosition->isActive()) {
			// If position is open, update it (check for DCA, etc.)
			$this->updatePosition($this->getCurrentPosition());
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
			$this->exchange->getLogger()->warning("No strategy set for market $this.");
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
	 * @param Money $amount Amount.
	 * @param float|null $price Price per unit.
	 * @return Money Quantity as string.
	 */
	public function calculateQuantity(Money $amount, ?float $price): Money {
		$pair = $this->getPair();
		if ($price) {
			// Limit orders.
			$quantity = $amount->getAmount() / $price;
		} else {
			// For market orders, use a rough estimate.
			$currentPrice = $this->getCurrentPrice()->getAmount();
			$quantity = $currentPrice ? ($amount->getAmount() / $currentPrice) : 0.001;
		}
		return Money::from($quantity, $pair->getBaseCurrency());
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

		$ticker = $this->getTicker();

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
				$this->getExchange()->getLogger()->info("Added indicator {$indicatorType} to market $this");
			} catch (Exception $e) {
				$this->getExchange()->getLogger()->error("Failed to add indicator {$indicatorType} to market $this: " . $e->getMessage());
			}
		}
	}

	private function getCurrentPrice(): Money {
		return $this->getExchange()->getCurrentPrice($this);
	}

	/**
	 * @param IPosition $currentPosition
	 * @return void
	 * Called only on an existent and active position.
	 * TODO: update position info from the exchange.
	 */
	private function updatePosition(IPosition $currentPosition): void {
		if (!method_exists($this->strategy, 'updatePosition')) {
			return;
		}
		
		// All checks passed, we can ask the Strategy to update our active Position.
		$this->strategy->updatePosition($currentPosition);
		$currentPosition->save();
	}
}
