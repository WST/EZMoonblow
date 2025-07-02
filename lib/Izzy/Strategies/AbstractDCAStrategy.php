<?php

namespace Izzy\Strategies;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPosition;

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
		
		/** Long */
		$numberOfLevels = $this->params['numberOfLevels'] ?? 5;
		$entryVolume = $this->params['entryVolume'] ?? 40;
		$volumeMultiplier = $this->params['volumeMultiplier'] ?? 2;
		$priceDeviation = $this->params['priceDeviation'] ?? 5;
		$priceDeviationMultiplier = $this->params['priceDeviationMultiplier'] ?? 2;
		$expectedProfit = $this->params['expectedProfit'] ?? 2;

		/** Short */
		$numberOfLevelsShort = $this->params['numberOfLevelsShort'] ?? 5;
		$entryVolumeShort = $this->params['entryVolumeShort'] ?? 40;
		$volumeMultiplierShort = $this->params['volumeMultiplierShort'] ?? 2;
		$priceDeviationShort = $this->params['priceDeviationShort'] ?? 5;
		$priceDeviationMultiplierShort = $this->params['priceDeviationMultiplierShort'] ?? 2;
		$expectedProfitShort = $this->params['expectedProfitShort'] ?? 2;

		// Remove % sign if present and convert to float
		$priceDeviation = (float) str_replace('%', '', $priceDeviation);
		$expectedProfit = (float) str_replace('%', '', $expectedProfit);
		$priceDeviationShort = (float) str_replace('%', '', $priceDeviationShort);
		$expectedProfitShort = (float) str_replace('%', '', $expectedProfitShort);

		$this->dcaSettings = new DCASettings(
			$useLimitOrders,
			$numberOfLevels,
			Money::from($entryVolume),
			$volumeMultiplier,
			$priceDeviation,
			$priceDeviationMultiplier,
			$expectedProfit,
			$numberOfLevelsShort,
			Money::from($entryVolumeShort),
			$volumeMultiplierShort,
			$priceDeviationShort,
			$priceDeviationMultiplierShort,
			$expectedProfitShort
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
	 * @return IPosition|false
	 */
	public function handleLong(IMarket $market): IPosition|false {
		if ($this->dcaSettings->isUseLimitOrders()) {
			return $market->openLongByLimitOrderMap(
				$this->dcaSettings->getOrderMap()[PositionDirectionEnum::LONG->value],
				$this->dcaSettings->getExpectedProfit()
			);
		} else {
			// Market open the Long position.
			return $market->openLongPosition(
				$this->getEntryVolume(),
				$this->dcaSettings->getExpectedProfit()
			);
		}
	}

	/**
	 * Here, we enter the Short position.
	 * @param IMarket $market
	 * @return IPosition|false
	 */
	public function handleShort(IMarket $market): IPosition|false {
		if ($this->dcaSettings->isUseLimitOrders()) {
			return $market->openShortByLimitOrderMap(
				$this->dcaSettings->getOrderMap()[PositionDirectionEnum::SHORT->value],
				$this->dcaSettings->getExpectedProfit()
			);
		} else {
			// Market open the Short position.
			return $market->openShortPosition(
				$this->getEntryVolume(),
				$this->dcaSettings->getExpectedProfit()
			);
		}
	}

	/**
	 * This strategy does not use stop loss.
	 * Instead, it relies on the DCA mechanism to average down the position.
	 * Since this is an abstract class, this method will call the getDCALevels() method
	 * of the child class to determine the DCA levels.
	 * @param IPosition $position
	 * @return void
	 */
	public function updatePosition(IPosition $position): void {
		// We don’t want to update position if it uses limit orders. There is nothing to update, orders are set up.
		if ($this->dcaSettings->isUseLimitOrders()) return;
		
		// Complete DCA map.
		$dcaLevels = $this->getDCALevels();
		
		// Separate maps for Long and Short positions.
		$dcaLevelsLong = $dcaLevels[PositionDirectionEnum::LONG->value]; krsort($dcaLevelsLong);
		$dcaLevelsShort = $dcaLevels[PositionDirectionEnum::SHORT->value]; krsort($dcaLevelsShort);

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
			if (abs($offset) < 0.1) continue;
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
			if (abs($offset) < 0.1) continue;
			if ($priceChangePercent >= $offset) {
				// Execute DCA buy order
				$dcaAmount = new Money($volume, 'USDT');
				$position->sellAdditional($dcaAmount);
				break;
			}
		}
	}

	/**
	 * Get DCA levels from DCASettings.
	 * @return array
	 */
	public function getDCALevels(): array {
		return $this->dcaSettings->getOrderMap();
	}

	/**
	 * Get DCA settings instance.
	 *
	 * @return DCASettings|null DCA settings or null if not initialized.
	 */
	public function getDCASettings(): ?DCASettings {
		return $this->dcaSettings;
	}
}
