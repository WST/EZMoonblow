<?php

namespace Izzy\Financial;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Parameters\AlwaysMarketEntry;
use Izzy\Financial\Parameters\ExpectedProfit;
use Izzy\Financial\Parameters\ExpectedProfitShort;
use Izzy\Financial\Parameters\InitialEntryVolume;
use Izzy\Financial\Parameters\InitialEntryVolumeShort;
use Izzy\Financial\Parameters\NumberOfLevels;
use Izzy\Financial\Parameters\NumberOfLevelsShort;
use Izzy\Financial\Parameters\OffsetMode;
use Izzy\Financial\Parameters\PriceDeviation;
use Izzy\Financial\Parameters\PriceDeviationMultiplier;
use Izzy\Financial\Parameters\PriceDeviationMultiplierShort;
use Izzy\Financial\Parameters\PriceDeviationShort;
use Izzy\Financial\Parameters\TwoWayMode;
use Izzy\Financial\Parameters\UseLimitOrders;
use Izzy\Financial\Parameters\VolumeMultiplier;
use Izzy\Financial\Parameters\VolumeMultiplierShort;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;

/**
 * Base class for Dollar-Cost Averaging (DCA) strategies.
 */
abstract class AbstractDCAStrategy extends AbstractStrategy
{
	protected DCASettings $dcaSettings;

	/**
	 * Tracks the highest filled DCA level per position.
	 * Maps "{positionId}_{L|S}" → level index.
	 * Prevents re-execution of already filled averaging levels.
	 * @var array<string, int>
	 */
	private array $dcaFilledLevels = [];

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->initializeDCASettings();
	}

	/**
	 * Initialize DCA settings from typed strategy parameter objects.
	 */
	private function initializeDCASettings(): void {
		$useLimitOrders = $this->params[UseLimitOrders::getName()]->getValue();

		/** Long parameters */
		$numberOfLevels = $this->params[NumberOfLevels::getName()]->getValue();
		$entryVolumeRaw = $this->params[InitialEntryVolume::getName()]->getRawValue();
		$volumeMultiplier = $this->params[VolumeMultiplier::getName()]->getValue();
		$priceDeviation = $this->params[PriceDeviation::getName()]->getValue();
		$priceDeviationMultiplier = $this->params[PriceDeviationMultiplier::getName()]->getValue();
		$expectedProfit = $this->params[ExpectedProfit::getName()]->getValue();

		/** Short parameters (only present in strategies that support shorts) */
		$numberOfLevelsShort = ($this->params[NumberOfLevelsShort::getName()] ?? null)?->getValue() ?? 0;
		$entryVolumeShortRaw = ($this->params[InitialEntryVolumeShort::getName()] ?? null)?->getRawValue() ?? '0';
		$volumeMultiplierShort = ($this->params[VolumeMultiplierShort::getName()] ?? null)?->getValue() ?? 0.0;
		$priceDeviationShort = ($this->params[PriceDeviationShort::getName()] ?? null)?->getValue() ?? 0.0;
		$priceDeviationMultiplierShort = ($this->params[PriceDeviationMultiplierShort::getName()] ?? null)?->getValue() ?? 0.0;
		$expectedProfitShort = ($this->params[ExpectedProfitShort::getName()] ?? null)?->getValue() ?? 0.0;

		$offsetModeParam = $this->params[OffsetMode::getName()]->getValue();
		$offsetMode = DCAOffsetModeEnum::tryFrom($offsetModeParam) ?? DCAOffsetModeEnum::FROM_ENTRY;

		$alwaysMarketEntry = $this->params[AlwaysMarketEntry::getName()]->getValue();

		$parsedVolume = EntryVolumeParser::parse($entryVolumeRaw);
		$entryVolume = $parsedVolume->getValue();
		$volumeMode = $parsedVolume->getMode();

		$parsedVolumeShort = EntryVolumeParser::parse($entryVolumeShortRaw);
		$entryVolumeShort = $parsedVolumeShort->getValue();
		$volumeModeShort = $parsedVolumeShort->getMode();

		$this->dcaSettings = new DCASettings(
			useLimitOrders: $useLimitOrders,
			numberOfLevels: $numberOfLevels,
			entryVolume: $entryVolume,
			volumeMultiplier: $volumeMultiplier,
			priceDeviation: $priceDeviation,
			priceDeviationMultiplier: $priceDeviationMultiplier,
			expectedProfit: $expectedProfit,
			volumeMode: $volumeMode,
			numberOfLevelsShort: $numberOfLevelsShort,
			entryVolumeShort: $entryVolumeShort,
			volumeMultiplierShort: $volumeMultiplierShort,
			priceDeviationShort: $priceDeviationShort,
			priceDeviationMultiplierShort: $priceDeviationMultiplierShort,
			expectedProfitShort: $expectedProfitShort,
			volumeModeShort: $volumeModeShort,
			offsetMode: $offsetMode,
			alwaysMarketEntry: $alwaysMarketEntry,
		);
	}

	/**
	 * This method should be implemented by child classes to determine when to enter long position.
	 * @return bool
	 */
	abstract public function shouldLong(): bool;

	/**
	 * In this base strategy, we never short.
	 * @return bool
	 */
	public function shouldShort(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function isTwoWayMode(): bool {
		return ($this->params[TwoWayMode::getName()] ?? null)?->getValue() === true;
	}

	/**
	 * Here, we enter the Long position.
	 * @param IMarket $market
	 * @return IStoredPosition|false
	 */
	public function handleLong(IMarket $market): IStoredPosition|false {
		if ($this->dcaSettings->isUseLimitOrders()) {
			$newPosition = $market->openPositionByDCAGrid($this->dcaSettings->getLongGrid());
		} else {
			$entryVolume = $this->resolveGridEntryVolume(PositionDirectionEnum::LONG);
			$newPosition = $market->openPosition(
				$entryVolume,
				PositionDirectionEnum::LONG,
				$this->dcaSettings->getLongGrid()->getExpectedProfit()
			);
		}
		$newPosition->setExpectedProfitPercent($this->dcaSettings->getLongGrid()->getExpectedProfit());
		$newPosition->save();
		return $newPosition;
	}

	/**
	 * Here, we enter the Short position.
	 * @param IMarket $market
	 * @return IStoredPosition|false
	 */
	public function handleShort(IMarket $market): IStoredPosition|false {
		if ($this->dcaSettings->isUseLimitOrders()) {
			$newPosition = $market->openPositionByDCAGrid($this->dcaSettings->getShortGrid());
		} else {
			$entryVolume = $this->resolveGridEntryVolume(PositionDirectionEnum::SHORT);
			$newPosition = $market->openPosition(
				$entryVolume,
				PositionDirectionEnum::SHORT,
				$this->dcaSettings->getShortGrid()->getExpectedProfit()
			);
		}
		$newPosition->setExpectedProfitPercent($this->dcaSettings->getShortGrid()->getExpectedProfit());
		$newPosition->save();
		return $newPosition;
	}

	/**
	 * Resolve the entry-level volume from the DCA grid, respecting the volume mode
	 * (absolute, % of balance, % of margin, base currency).
	 *
	 * @param PositionDirectionEnum $direction Position direction.
	 * @return Money Resolved entry volume in quote currency.
	 */
	protected function resolveGridEntryVolume(PositionDirectionEnum $direction): Money {
		$grid = $direction->isLong()
			? $this->dcaSettings->getLongGrid()
			: $this->dcaSettings->getShortGrid();

		$levels = $grid->getLevels();
		if (empty($levels)) {
			return Money::from(0);
		}

		$context = $this->market->getTradingContext();
		return Money::from($levels[0]->resolveVolume($context));
	}

	/**
	 * Update an existing position: check if the next DCA averaging level
	 * should be triggered and execute it.
	 *
	 * For limit-order mode, DCA fills are handled by the backtester loop
	 * (pending limit orders), so we only recalculate TP here.
	 *
	 * For market-order mode, we iterate DCA levels sequentially (low to
	 * high) and trigger at most one level per call. A tracking map
	 * ($dcaFilledLevels) prevents re-execution of already filled levels.
	 *
	 * The position object is modified in-place so that the caller's save()
	 * persists volume, average entry, and TP changes correctly.
	 *
	 * @param IStoredPosition $position Position to update.
	 * @return void
	 */
	public function updatePosition(IStoredPosition $position): void {
		if ($this->dcaSettings->isUseLimitOrders()) {
			$position->updateTakeProfit($this->market);
			return;
		}

		$entryPrice = $position->getEntryPrice()->getAmount();
		$currentPrice = $position->getCurrentPrice()->getAmount();
		if ($entryPrice <= 0 || $currentPrice <= 0) {
			return;
		}
		$priceChangePercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;

		$posKey = $position->getId() ?? spl_object_id($position);
		$context = $this->market->getTradingContext();
		$direction = $position->getDirection();

		$grid = $direction->isLong()
			? $this->dcaSettings->getLongGrid()
			: $this->dcaSettings->getShortGrid();

		$levels = $grid->buildOrderMap($context);
		ksort($levels);
		$filledKey = $posKey . '_' . ($direction->isLong() ? 'L' : 'S');
		$filled = $this->dcaFilledLevels[$filledKey] ?? 0;

		foreach ($levels as $idx => $level) {
			if ($idx <= $filled) {
				continue;
			}
			if (abs($level['offset']) < 0.01) {
				continue;
			}
			$triggered = $direction->isLong()
				? ($priceChangePercent <= $level['offset'])
				: ($priceChangePercent >= $level['offset']);
			if ($triggered) {
				$this->executeDCAFill($position, $level['volume'], $currentPrice);
				$this->dcaFilledLevels[$filledKey] = $idx;
			}
			break;
		}
	}

	/**
	 * Execute a DCA fill: add volume to the position, recalculate average
	 * entry price and take-profit. All changes are made in-place on the
	 * position object (the caller is responsible for saving).
	 *
	 * @param IStoredPosition $position Position to average into.
	 * @param float $addedVolumeQuote Additional volume in quote currency (USDT).
	 * @param float $fillPrice Price at which the averaging occurs.
	 */
	private function executeDCAFill(IStoredPosition $position, float $addedVolumeQuote, float $fillPrice): void {
		$oldVolBase = $position->getVolume()->getAmount();
		$oldAvgEntry = $position->getAverageEntryPrice()->getAmount();
		$addedBase = $addedVolumeQuote / $fillPrice;
		$newVolBase = $oldVolBase + $addedBase;

		if ($newVolBase <= 0) {
			return;
		}

		$newAvgEntry = ($oldVolBase * $oldAvgEntry + $addedBase * $fillPrice) / $newVolBase;

		$pair = $this->market->getPair();
		$position->setVolume(Money::from($newVolBase, $pair->getBaseCurrency()));
		$position->setAverageEntryPrice(Money::from($newAvgEntry, $pair->getQuoteCurrency()));

		// Recalculate TP from the new average entry.
		$percent = $position->getExpectedProfitPercent();
		if (abs($percent) >= 0.0001) {
			$avgMoney = Money::from($newAvgEntry, $pair->getQuoteCurrency());
			$position->setTakeProfitPrice(
				$avgMoney->modifyByPercentWithDirection($percent, $position->getDirection())
			);
		}
	}

	/**
	 * Get DCA settings instance.
	 *
	 * @return DCASettings|null DCA settings or null if not initialized.
	 */
	public function getDCASettings(): ?DCASettings {
		return $this->dcaSettings;
	}

	// ------------------------------------------------------------------
	// Parameter definitions
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 *
	 * @return AbstractStrategyParameter[]
	 */
	public static function getParameters(): array {
		return [
			new NumberOfLevels(),
			new InitialEntryVolume(),
			new VolumeMultiplier(),
			new PriceDeviation(),
			new PriceDeviationMultiplier(),
			new ExpectedProfit(),
			new UseLimitOrders(),
			new OffsetMode(),
			new AlwaysMarketEntry(),
		];
	}

	// ------------------------------------------------------------------
	// Exchange settings validation
	// ------------------------------------------------------------------

	/**
	 * @inheritDoc
	 *
	 * DCA strategies require hedge position mode on futures exchanges.
	 */
	public function validateExchangeSettings(IMarket $market): StrategyValidationResult {
		$result = parent::validateExchangeSettings($market);

		// DCA strategies require hedge mode on futures.
		if ($market->getMarketType()->isFutures()) {
			$positionMode = $market->getExchange()->getPositionMode($market);
			if ($positionMode === null) {
				$result->addWarning(
					'Could not verify position mode on the exchange (API error). '
					. 'DCA strategy requires Hedge mode (Two-Way) to function correctly.'
				);
			} elseif (!$positionMode->isHedge()) {
				$result->addError(
					'DCA strategy requires Hedge position mode (Two-Way), '
					. "but the exchange is configured as '{$positionMode->getLabel()}'. "
					. 'Please switch to Hedge mode in exchange settings.'
				);
			}
		}

		return $result;
	}

	abstract public function doesLong(): bool;
	abstract public function doesShort(): bool;

	/**
	 * Short-specific parameter names.
	 * These are excluded from display when doesShort() returns false.
	 */
	private static function getShortParams(): array {
		return [
			NumberOfLevelsShort::getName(),
			InitialEntryVolumeShort::getName(),
			VolumeMultiplierShort::getName(),
			PriceDeviationShort::getName(),
			PriceDeviationMultiplierShort::getName(),
			ExpectedProfitShort::getName(),
		];
	}

	/**
	 * Long-specific parameter names.
	 * These are excluded from display when doesLong() returns false.
	 */
	private static function getLongParams(): array {
		return [
			NumberOfLevels::getName(),
			InitialEntryVolume::getName(),
			VolumeMultiplier::getName(),
			PriceDeviation::getName(),
			PriceDeviationMultiplier::getName(),
			ExpectedProfit::getName(),
		];
	}

	/**
	 * Get strategy parameters filtered by the directions this strategy supports.
	 *
	 * If the strategy does not short, Short-specific parameters are excluded.
	 * If the strategy does not long, Long-specific parameters are excluded.
	 * Common parameters (UseLimitOrders, offsetMode, alwaysMarketEntry, etc.)
	 * are always included.
	 *
	 * @return array Filtered parameters suitable for display.
	 */
	public function getDisplayParameters(): array {
		$excluded = [];
		if (!$this->doesShort()) {
			$excluded = array_merge($excluded, self::getShortParams());
		}
		if (!$this->doesLong()) {
			$excluded = array_merge($excluded, self::getLongParams());
		}

		$filtered = array_diff_key($this->params, array_flip($excluded));
		$raw = [];
		foreach ($filtered as $name => $param) {
			$raw[$name] = $param->getRawValue();
		}
		return $raw;
	}

	public static function getStrategySettingGroupTitle(): string {
		return 'DCA Settings';
	}
}
