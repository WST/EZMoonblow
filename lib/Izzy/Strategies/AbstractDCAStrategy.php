<?php

namespace Izzy\Strategies;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStoredPosition;

/**
 * Base class for Dollar-Cost Averaging (DCA) strategies.
 */
abstract class AbstractDCAStrategy extends Strategy
{
	protected DCASettings $dcaSettings;

	public function __construct(IMarket $market, array $params = []) {
		parent::__construct($market, $params);
		$this->initializeDCASettings();
	}

	/**
	 * Initialize DCA settings from strategy parameters.
	 */
	private function initializeDCASettings(): void {
		/** Use limit orders */
		$useLimitOrders = ($this->params['UseLimitOrders'] == 'yes');

		/** Long parameters */
		$numberOfLevels = $this->params['numberOfLevels'] ?? 5;
		$entryVolumeRaw = $this->params['entryVolume'] ?? 40;
		$volumeMultiplier = $this->params['volumeMultiplier'] ?? 2;
		$priceDeviation = $this->params['priceDeviation'] ?? 5;
		$priceDeviationMultiplier = $this->params['priceDeviationMultiplier'] ?? 2;
		$expectedProfit = $this->params['expectedProfit'] ?? 2;

		/** Short parameters */
		$numberOfLevelsShort = $this->params['numberOfLevelsShort'] ?? 0;
		$entryVolumeShortRaw = $this->params['entryVolumeShort'] ?? 0;
		$volumeMultiplierShort = $this->params['volumeMultiplierShort'] ?? 2;
		$priceDeviationShort = $this->params['priceDeviationShort'] ?? 5;
		$priceDeviationMultiplierShort = $this->params['priceDeviationMultiplierShort'] ?? 2;
		$expectedProfitShort = $this->params['expectedProfitShort'] ?? 2;

		/** Offset calculation mode */
		$offsetModeParam = $this->params['offsetMode'] ?? DCAOffsetModeEnum::FROM_ENTRY->value;
		$offsetMode = DCAOffsetModeEnum::tryFrom($offsetModeParam) ?? DCAOffsetModeEnum::FROM_ENTRY;

		/** Always execute entry order as market instead of limit */
		$alwaysMarketEntry = ($this->params['alwaysMarketEntry'] ?? 'no') === 'yes';

		// Parse entry volume for Long (supports: "140", "5%", "5%M", "0.002 BTC")
		$parsedVolume = EntryVolumeParser::parse($entryVolumeRaw);
		$entryVolume = $parsedVolume->getValue();
		$volumeMode = $parsedVolume->getMode();

		// Parse entry volume for Short
		$parsedVolumeShort = EntryVolumeParser::parse($entryVolumeShortRaw);
		$entryVolumeShort = $parsedVolumeShort->getValue();
		$volumeModeShort = $parsedVolumeShort->getMode();

		// Remove % sign if present and convert to float for price deviations
		$priceDeviation = (float)str_replace('%', '', $priceDeviation);
		$expectedProfit = (float)str_replace('%', '', $expectedProfit);
		$priceDeviationShort = (float)str_replace('%', '', $priceDeviationShort);
		$expectedProfitShort = (float)str_replace('%', '', $expectedProfitShort);

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
	 * Here, we enter the Long position.
	 * @param IMarket $market
	 * @return IStoredPosition|false
	 */
	public function handleLong(IMarket $market): IStoredPosition|false {
		if ($this->dcaSettings->isUseLimitOrders()) {
			$newPosition = $market->openPositionByDCAGrid($this->dcaSettings->getLongGrid());
		} else {
			// Market open the Long position.
			$newPosition = $market->openPosition(
				$this->getEntryVolume(),
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
			// Market open the Short position.
			$newPosition = $market->openPosition(
				$this->getEntryVolume(),
				PositionDirectionEnum::SHORT,
				$this->dcaSettings->getShortGrid()->getExpectedProfit()
			);
		}
		$newPosition->setExpectedProfitPercent($this->dcaSettings->getShortGrid()->getExpectedProfit());
		$newPosition->save();
		return $newPosition;
	}

	/**
	 * This strategy does not use stop loss.
	 * Instead, it relies on the DCA mechanism to average down the position.
	 * Uses the DCA order grids to determine when to average.
	 *
	 * @param IStoredPosition $position Position to update.
	 * @return void
	 */
	public function updatePosition(IStoredPosition $position): void {
		// If the position uses limit orders, we only need to move TP order.
		if ($this->dcaSettings->isUseLimitOrders()) {
			$position->updateTakeProfit($this->market);
			return;
		}

		$context = $this->market->getTradingContext();
		$longGrid = $this->dcaSettings->getLongGrid();
		$shortGrid = $this->dcaSettings->getShortGrid();

		// Build order maps from grids.
		$dcaLevelsLong = $longGrid->buildOrderMap($context);
		krsort($dcaLevelsLong);
		$dcaLevelsShort = $shortGrid->buildOrderMap($context);
		krsort($dcaLevelsShort);

		/**
		 * 0 — initial entry, i.e: 0 => ['volume' => 100, 'offset' => 0]
		 * 1 — first averaging, i.e: 1 => ['volume' => 200, 'offset' => -5]
		 * ...
		 * offset should be negative for Long, positive for Short trades.
		 */

		$entryPrice = $position->getEntryPrice()->getAmount();
		$currentPrice = $position->getCurrentPrice()->getAmount();

		// Calculate current price drop percentage.
		$priceChangePercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;
		echo "PRICE CHANGE IN %: $priceChangePercent\n";

		// Check if we should execute DCA. Long first.
		foreach ($dcaLevelsLong as $level) {
			$volume = $level['volume'];
			$offset = $level['offset'];
			if (abs($offset) < 0.1)
				continue;
			if ($priceChangePercent <= $offset) {
				// Execute DCA buy order.
				$dcaAmount = new Money($volume, 'USDT');
				$position->buyAdditional($dcaAmount);
				break;
			}
		}

		// Check if we should execute DCA. Now for Short.
		foreach ($dcaLevelsShort as $level) {
			$volume = $level['volume'];
			$offset = $level['offset'];
			if (abs($offset) < 0.1)
				continue;
			if ($priceChangePercent >= $offset) {
				// Execute DCA sell order.
				$dcaAmount = new Money($volume, 'USDT');
				$position->sellAdditional($dcaAmount);
				break;
			}
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

	/**
	 * Convert machine-readable parameter names to human-readable format.
	 * @param string $paramName Machine-readable parameter name.
	 * @return string Human-readable parameter name.
	 */
	public static function formatParameterName(string $paramName): string {
		$formattedNames = [
			'numberOfLevels' => 'Number of DCA orders including the entry order',
			'entryVolume' => 'Initial entry volume (USDT, %, %M, or base currency)',
			'volumeMultiplier' => 'Volume multiplier for each subsequent order',
			'priceDeviation' => 'Price deviation for first averaging (%)',
			'priceDeviationMultiplier' => 'Price deviation multiplier for subsequent orders',
			'expectedProfit' => 'Expected profit percentage',
			'UseLimitOrders' => 'Use limit orders instead of market orders',
			'offsetMode' => 'Price offset calculation mode',
			'alwaysMarketEntry' => 'Always execute entry order as market',
			'numberOfLevelsShort' => 'Number of short DCA orders including the entry order',
			'entryVolumeShort' => 'Initial short entry volume (USDT, %, %M, or base currency)',
			'volumeMultiplierShort' => 'Short volume multiplier for each subsequent order',
			'priceDeviationShort' => 'Short price deviation for first averaging (%)',
			'priceDeviationMultiplierShort' => 'Short price deviation multiplier for subsequent orders',
			'expectedProfitShort' => 'Expected short profit percentage',
		];

		return $formattedNames[$paramName] ?? $paramName;
	}

	/**
	 * Format parameter value for human-readable display.
	 * Converts boolean-like values (yes/no/1/0) to Yes/No,
	 * enum values to their descriptions, etc.
	 *
	 * @param string $paramName Parameter name.
	 * @param string $value Raw parameter value.
	 * @return string Formatted parameter value.
	 */
	public static function formatParameterValue(string $paramName, string $value): string {
		$booleanParams = ['UseLimitOrders', 'alwaysMarketEntry'];
		if (in_array($paramName, $booleanParams)) {
			return match (strtolower($value)) {
				'yes', '1', 'true' => 'Yes',
				'no', '0', 'false', '' => 'No',
				default => $value,
			};
		}

		if ($paramName === 'offsetMode') {
			$mode = DCAOffsetModeEnum::tryFrom($value);
			if ($mode !== null) {
				return $mode->getDescription();
			}
		}

		return $value;
	}

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
			if (!$positionMode->isHedge()) {
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
	private const array SHORT_PARAMS = [
		'numberOfLevelsShort',
		'entryVolumeShort',
		'volumeMultiplierShort',
		'priceDeviationShort',
		'priceDeviationMultiplierShort',
		'expectedProfitShort',
	];

	/**
	 * Long-specific parameter names.
	 * These are excluded from display when doesLong() returns false.
	 */
	private const array LONG_PARAMS = [
		'numberOfLevels',
		'entryVolume',
		'volumeMultiplier',
		'priceDeviation',
		'priceDeviationMultiplier',
		'expectedProfit',
	];

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
			$excluded = array_merge($excluded, self::SHORT_PARAMS);
		}
		if (!$this->doesLong()) {
			$excluded = array_merge($excluded, self::LONG_PARAMS);
		}

		return array_diff_key($this->params, array_flip($excluded));
	}
}
