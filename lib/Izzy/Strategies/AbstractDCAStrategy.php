<?php

namespace Izzy\Strategies;

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
		$numberOfLevels = $this->params['numberOfLevels'] ?? 6;
		$entryVolume = $this->params['entryVolume'] ?? 40;
		$volumeMultiplier = $this->params['volumeMultiplier'] ?? 2;
		$priceDeviation = $this->params['priceDeviation'] ?? 5;
		$priceDeviationMultiplier = $this->params['priceDeviationMultiplier'] ?? 2;
		$expectedProfit = $this->params['expectedProfit'] ?? 2;

		// Remove % sign if present and convert to float
		$priceDeviation = (float) str_replace('%', '', $priceDeviation);
		$expectedProfit = (float) str_replace('%', '', $expectedProfit);

		$this->dcaSettings = new DCASettings(
			$numberOfLevels,
			Money::from($entryVolume, 'USDT'),
			$volumeMultiplier,
			$priceDeviation,
			$priceDeviationMultiplier,
			$expectedProfit
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
	
	public function handleLong(IMarket $market): IPosition|false {
		return $market->openLongPosition(Money::from(10.0));
	}

	public function handleShort(IMarket $market): IPosition|false {
		return false;
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
		$dcaLevels = $this->getDCALevels();
		$entryPrice = $position->getEntryPrice()->toFloat();
		$currentPrice = $position->getCurrentPrice();
		
		// Calculate current price drop percentage
		$priceDropPercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;
		
		// Check if we should execute DCA
		foreach ($dcaLevels as $level) {
			if ($priceDropPercent <= $level) {
				// Execute DCA buy order
				$exchange = $this->market->getExchange();
				$dcaAmount = new Money(5.0, 'USDT'); // $5 DCA amount
				$exchange->buyAdditional($this->market->getPair(), $dcaAmount);
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
