<?php

namespace Izzy\Strategies;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;

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
	 * Initial position volume.
	 * @var Money
	 */
	private Money $entryVolume;

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
	 * Initial position volume.
	 * @var Money
	 */
	private Money $entryVolumeShort;

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
	 * @param Money $entryVolume Initial position volume.
	 * @param float $volumeMultiplier How much should be the next order greater than the previous one.
	 * @param float $priceDeviation The distance between the entry and the first averaging.
	 * @param float $priceDeviationMultiplier How much further (in percent) should be the next order from the entry price.
	 * @param float $expectedProfit Expected profit in percents of the position size.
	 * @param int $numberOfLevelsShort Number of DCA levels for short trades.
	 * @param Money|null $entryVolumeShort Initial position volume for short trades.
	 * @param float $volumeMultiplierShort Volume multiplier for short trades.
	 * @param float $priceDeviationShort Price deviation for short trades.
	 * @param float $priceDeviationMultiplierShort Price deviation multiplier for short trades.
	 * @param float $expectedProfitShort Expected profit for short trades.
	 * @param DCAOffsetModeEnum $offsetMode How price offsets should be calculated.
	 */
	public function __construct(
		bool $useLimitOrders,
		int $numberOfLevels,
		Money $entryVolume,
		float $volumeMultiplier,
		float $priceDeviation,
		float $priceDeviationMultiplier,
		float $expectedProfit,
		int $numberOfLevelsShort = 0,
		?Money $entryVolumeShort = null,
		float $volumeMultiplierShort = 0.0,
		float $priceDeviationShort = 0.0,
		float $priceDeviationMultiplierShort = 0.0,
		float $expectedProfitShort = 0.0,
		DCAOffsetModeEnum $offsetMode = DCAOffsetModeEnum::FROM_ENTRY
	) {
		$this->offsetMode = $offsetMode;

		// Build the order grid for Long trades.
		$this->longGrid = DCAOrderGrid::fromParameters(
			$numberOfLevels,
			$entryVolume->getAmount(),
			$volumeMultiplier,
			$priceDeviation,
			$priceDeviationMultiplier,
			$offsetMode
		);

		// Build the order grid for Short trades.
		if ($entryVolumeShort && $numberOfLevelsShort > 0) {
			$this->shortGrid = DCAOrderGrid::fromParameters(
				$numberOfLevelsShort,
				$entryVolumeShort->getAmount(),
				$volumeMultiplierShort,
				$priceDeviationShort,
				$priceDeviationMultiplierShort,
				$offsetMode
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
		$this->entryVolumeShort = $entryVolumeShort ?? Money::from(0);
		$this->volumeMultiplierShort = $volumeMultiplierShort;
		$this->priceDeviationShort = $priceDeviationShort;
		$this->priceDeviationMultiplierShort = $priceDeviationMultiplierShort;
		$this->expectedProfitShort = $expectedProfitShort;
		$this->useLimitOrders = $useLimitOrders;
	}

	/**
	 * Get the order map for both Long and Short trades.
	 * This method maintains backward compatibility with existing code.
	 *
	 * @return array Order map with PositionDirectionEnum values as keys.
	 */
	public function getOrderMap(): array {
		return [
			PositionDirectionEnum::LONG->value => $this->longGrid->buildOrderMap(PositionDirectionEnum::LONG),
			PositionDirectionEnum::SHORT->value => $this->shortGrid->buildOrderMap(PositionDirectionEnum::SHORT),
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

	public function getMaxTotalPositionVolume(): Money {
		$totalVolume = $this->longGrid->getTotalVolume() + $this->shortGrid->getTotalVolume();
		return Money::from($totalVolume);
	}

	public function getMaxLongPositionVolume(): Money {
		return Money::from($this->longGrid->getTotalVolume());
	}

	public function getMaxShortPositionVolume(): Money {
		return Money::from($this->shortGrid->getTotalVolume());
	}

	public function getNumberOfLevels(): int {
		return $this->numberOfLevels;
	}

	public function getEntryVolume(): Money {
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

	public function isUseLimitOrders(): bool {
		return $this->useLimitOrders;
	}
}
