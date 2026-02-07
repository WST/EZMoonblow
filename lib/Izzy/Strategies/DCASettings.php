<?php

namespace Izzy\Strategies;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\EntryVolumeModeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;
use Izzy\Financial\TradingContext;

/**
 * Represents a DCA strategy settings.
 */
class DCASettings {
	/**
	 * Order grid for Long trades.
	 * @var DCAOrderGrid
	 */
	private DCAOrderGrid $longGrid;

	/**
	 * Order grid for Short trades.
	 * @var DCAOrderGrid
	 */
	private DCAOrderGrid $shortGrid;

	/**
	 * Use limit orders (or market orders otherwise).
	 * @var bool
	 */
	private bool $useLimitOrders;

	/**
	 * Builds a DCASettings instance.
	 * @param bool $useLimitOrders Use limit orders instead of market.
	 * @param int $numberOfLevels Number of DCA levels, including position entry.
	 * @param float $entryVolume Initial position volume (raw value).
	 * @param float $volumeMultiplier How much should be the next order greater than the previous one.
	 * @param float $priceDeviation The distance between the entry and the first averaging.
	 * @param float $priceDeviationMultiplier How much further (in percent) should be the next order from the entry price.
	 * @param float $expectedProfit Expected profit in percents of the position size.
	 * @param EntryVolumeModeEnum $volumeMode How Long entry volume should be interpreted.
	 * @param int $numberOfLevelsShort Number of DCA levels for short trades.
	 * @param float $entryVolumeShort Initial position volume for short trades (raw value).
	 * @param float $volumeMultiplierShort Volume multiplier for short trades.
	 * @param float $priceDeviationShort Price deviation for short trades.
	 * @param float $priceDeviationMultiplierShort Price deviation multiplier for short trades.
	 * @param float $expectedProfitShort Expected profit for short trades.
	 * @param EntryVolumeModeEnum $volumeModeShort How Short entry volume should be interpreted.
	 * @param DCAOffsetModeEnum $offsetMode How price offsets should be calculated.
	 */
	public function __construct(
		bool $useLimitOrders,
		int $numberOfLevels,
		float $entryVolume,
		float $volumeMultiplier,
		float $priceDeviation,
		float $priceDeviationMultiplier,
		float $expectedProfit,
		EntryVolumeModeEnum $volumeMode = EntryVolumeModeEnum::ABSOLUTE_QUOTE,
		int $numberOfLevelsShort = 0,
		float $entryVolumeShort = 0.0,
		float $volumeMultiplierShort = 0.0,
		float $priceDeviationShort = 0.0,
		float $priceDeviationMultiplierShort = 0.0,
		float $expectedProfitShort = 0.0,
		EntryVolumeModeEnum $volumeModeShort = EntryVolumeModeEnum::ABSOLUTE_QUOTE,
		DCAOffsetModeEnum $offsetMode = DCAOffsetModeEnum::FROM_ENTRY
	) {
		// Build the order grid for Long trades.
		$this->longGrid = DCAOrderGrid::fromParameters(
			$numberOfLevels,
			$entryVolume,
			$volumeMultiplier,
			$priceDeviation,
			$priceDeviationMultiplier,
			PositionDirectionEnum::LONG,
			$expectedProfit,
			$offsetMode,
			$volumeMode
		);

		// Build the order grid for Short trades.
		if ($entryVolumeShort > 0 && $numberOfLevelsShort > 0) {
			$this->shortGrid = DCAOrderGrid::fromParameters(
				$numberOfLevelsShort,
				$entryVolumeShort,
				$volumeMultiplierShort,
				$priceDeviationShort,
				$priceDeviationMultiplierShort,
				PositionDirectionEnum::SHORT,
				$expectedProfitShort,
				$offsetMode,
				$volumeModeShort
			);
		} else {
			$this->shortGrid = new DCAOrderGrid($offsetMode, PositionDirectionEnum::SHORT, 0.0);
		}

		// Store settings.
		$this->useLimitOrders = $useLimitOrders;
	}

	/**
	 * Get the Long order grid.
	 * @return DCAOrderGrid
	 */
	public function getLongGrid(): DCAOrderGrid {
		return $this->longGrid;
	}

	/**
	 * Get the Short order grid.
	 * @return DCAOrderGrid
	 */
	public function getShortGrid(): DCAOrderGrid {
		return $this->shortGrid;
	}

	/**
	 * Get the offset calculation mode.
	 * @return DCAOffsetModeEnum
	 */
	public function getOffsetMode(): DCAOffsetModeEnum {
		return $this->longGrid->getOffsetMode();
	}

	/**
	 * Check if any grid requires runtime calculation.
	 * @return bool
	 */
	public function requiresRuntimeCalculation(): bool {
		return $this->longGrid->requiresRuntimeCalculation() || $this->shortGrid->requiresRuntimeCalculation();
	}

	public function getMaxTotalPositionVolume(TradingContext $context): Money {
		$totalVolume = $this->longGrid->getTotalVolume($context)
			+ $this->shortGrid->getTotalVolume($context);
		return Money::from($totalVolume);
	}

	public function getMaxLongPositionVolume(TradingContext $context): Money {
		return Money::from($this->longGrid->getTotalVolume($context));
	}

	public function getMaxShortPositionVolume(TradingContext $context): Money {
		return Money::from($this->shortGrid->getTotalVolume($context));
	}

	public function isUseLimitOrders(): bool {
		return $this->useLimitOrders;
	}
}
