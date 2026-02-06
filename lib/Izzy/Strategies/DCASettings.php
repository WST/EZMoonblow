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
	 * Number of DCA levels, including position entry.
	 * @var int
	 */
	private int $numberOfLevels;

	/**
	 * Initial position volume (raw value).
	 * @var float
	 */
	private float $entryVolume;

	/**
	 * Volume mode for Long trades.
	 * @var EntryVolumeModeEnum
	 */
	private EntryVolumeModeEnum $volumeMode;

	/**
	 * Martingale coefficient.
	 * @var float
	 */
	private float $volumeMultiplier;

	private float $priceDeviation;
	private float $priceDeviationMultiplier;
	private float $expectedProfit;

	/**
	 * Number of DCA levels, including position entry.
	 * @var int
	 */
	private int $numberOfLevelsShort;

	/**
	 * Initial position volume (raw value).
	 * @var float
	 */
	private float $entryVolumeShort;

	/**
	 * Volume mode for Short trades.
	 * @var EntryVolumeModeEnum
	 */
	private EntryVolumeModeEnum $volumeModeShort;

	/**
	 * Martingale coefficient.
	 * @var float
	 */
	private float $volumeMultiplierShort;

	private float $priceDeviationShort;
	private float $priceDeviationMultiplierShort;
	private float $expectedProfitShort;
	private bool $useLimitOrders;

	/**
	 * Offset calculation mode for the order grid.
	 * @var DCAOffsetModeEnum
	 */
	private DCAOffsetModeEnum $offsetMode;

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
		$this->offsetMode = $offsetMode;
		$this->volumeMode = $volumeMode;
		$this->volumeModeShort = $volumeModeShort;

		// Build the order grid for Long trades.
		$this->longGrid = DCAOrderGrid::fromParameters(
			$numberOfLevels,
			$entryVolume,
			$volumeMultiplier,
			$priceDeviation,
			$priceDeviationMultiplier,
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
				$offsetMode,
				$volumeModeShort
			);
		} else {
			$this->shortGrid = new DCAOrderGrid($offsetMode);
		}

		// Store settings for Long trades.
		$this->numberOfLevels = $numberOfLevels;
		$this->entryVolume = $entryVolume;
		$this->volumeMultiplier = $volumeMultiplier;
		$this->priceDeviation = $priceDeviation;
		$this->priceDeviationMultiplier = $priceDeviationMultiplier;
		$this->expectedProfit = $expectedProfit;

		// Store settings for Short trades.
		$this->numberOfLevelsShort = $numberOfLevelsShort;
		$this->entryVolumeShort = $entryVolumeShort;
		$this->volumeMultiplierShort = $volumeMultiplierShort;
		$this->priceDeviationShort = $priceDeviationShort;
		$this->priceDeviationMultiplierShort = $priceDeviationMultiplierShort;
		$this->expectedProfitShort = $expectedProfitShort;
		$this->useLimitOrders = $useLimitOrders;
	}

	/**
	 * Get the order map for both Long and Short trades.
	 *
	 * @param TradingContext $context Runtime trading context for volume resolution.
	 * @return array Order map with PositionDirectionEnum values as keys.
	 */
	public function getOrderMap(TradingContext $context): array {
		return [
			PositionDirectionEnum::LONG->value => $this->longGrid->buildOrderMap(
				PositionDirectionEnum::LONG,
				$context
			),
			PositionDirectionEnum::SHORT->value => $this->shortGrid->buildOrderMap(
				PositionDirectionEnum::SHORT,
				$context
			),
		];
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
		return $this->offsetMode;
	}

	/**
	 * Get the volume mode for Long trades.
	 * @return EntryVolumeModeEnum
	 */
	public function getVolumeMode(): EntryVolumeModeEnum {
		return $this->volumeMode;
	}

	/**
	 * Get the volume mode for Short trades.
	 * @return EntryVolumeModeEnum
	 */
	public function getVolumeModeShort(): EntryVolumeModeEnum {
		return $this->volumeModeShort;
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

	public function getNumberOfLevels(): int {
		return $this->numberOfLevels;
	}

	public function getEntryVolume(): float {
		return $this->entryVolume;
	}

	public function getVolumeMultiplier(): float {
		return $this->volumeMultiplier;
	}

	public function getPriceDeviation(): float {
		return $this->priceDeviation;
	}

	public function getPriceDeviationMultiplier(): float {
		return $this->priceDeviationMultiplier;
	}

	public function getExpectedProfit(): float {
		return $this->expectedProfit;
	}

	public function getExpectedProfitShort(): float {
		return $this->expectedProfitShort;
	}

	public function isUseLimitOrders(): bool {
		return $this->useLimitOrders;
	}
}
