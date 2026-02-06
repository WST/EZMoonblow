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
abstract class AbstractDCAStrategy extends Strategy {
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
			offsetMode: $offsetMode
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
			$context = $market->getTradingContext();
			$orderMap = $this->dcaSettings->getOrderMap($context);

			$newPosition = $market->openPositionByLimitOrderMap(
				$orderMap[PositionDirectionEnum::LONG->value],
				PositionDirectionEnum::LONG,
				$this->dcaSettings->getExpectedProfit()
			);
		} else {
			// Market open the Long position.
			$newPosition = $market->openPosition(
				$this->getEntryVolume(),
				PositionDirectionEnum::LONG,
				$this->dcaSettings->getExpectedProfit()
			);
		}
		$newPosition->setExpectedProfitPercent($this->dcaSettings->getExpectedProfit());
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
			$context = $market->getTradingContext();
			$orderMap = $this->dcaSettings->getOrderMap($context);

			return $market->openPositionByLimitOrderMap(
				$orderMap[PositionDirectionEnum::SHORT->value],
				PositionDirectionEnum::SHORT,
				$this->dcaSettings->getExpectedProfit()
			);
		} else {
			// Market open the Short position.
			return $market->openPosition(
				$this->getEntryVolume(),
				PositionDirectionEnum::SHORT,
				$this->dcaSettings->getExpectedProfit()
			);
		}
	}

	/**
	 * This strategy does not use stop loss.
	 * Instead, it relies on the DCA mechanism to average down the position.
	 * Since this is an abstract class, this method will call the getDCALevels() method
	 * of the child class to determine the DCA levels.
	 * @param IStoredPosition $position
	 * @return void
	 */
	public function updatePosition(IStoredPosition $position): void {
		// If the position uses limit orders, we only need to move TP order.
		if ($this->dcaSettings->isUseLimitOrders()) {
			$position->updateTakeProfit();
			return;
		}

		// Complete DCA map with runtime context.
		$dcaLevels = $this->getDCALevels();

		// Separate maps for Long and Short positions.
		$dcaLevelsLong = $dcaLevels[PositionDirectionEnum::LONG->value];
		krsort($dcaLevelsLong);
		$dcaLevelsShort = $dcaLevels[PositionDirectionEnum::SHORT->value];
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
				// Execute DCA buy order
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
				// Execute DCA buy order
				$dcaAmount = new Money($volume, 'USDT');
				$position->sellAdditional($dcaAmount);
				break;
			}
		}
	}

	/**
	 * Get DCA levels from DCASettings with runtime context.
	 * @return array
	 */
	public function getDCALevels(): array {
		$context = $this->market->getTradingContext();
		return $this->dcaSettings->getOrderMap($context);
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
			'numberOfLevelsShort' => 'Number of short DCA orders including the entry order',
			'entryVolumeShort' => 'Initial short entry volume (USDT, %, %M, or base currency)',
			'volumeMultiplierShort' => 'Short volume multiplier for each subsequent order',
			'priceDeviationShort' => 'Short price deviation for first averaging (%)',
			'priceDeviationMultiplierShort' => 'Short price deviation multiplier for subsequent orders',
			'expectedProfitShort' => 'Expected short profit percentage',
		];

		return $formattedNames[$paramName] ?? $paramName;
	}
}
