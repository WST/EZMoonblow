<?php

namespace Izzy\Strategies;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\PositionDirectionEnum;

/**
 * Represents a DCA order grid with support for different offset calculation modes.
 */
class DCAOrderGrid {
	/**
	 * Order levels in the grid.
	 * @var DCAOrderLevel[]
	 */
	private array $levels = [];

	/**
	 * Offset calculation mode.
	 * @var DCAOffsetModeEnum
	 */
	private DCAOffsetModeEnum $offsetMode;

	/**
	 * Creates a new DCA order grid.
	 *
	 * @param DCAOffsetModeEnum $offsetMode How offsets should be calculated (default: FROM_ENTRY).
	 */
	public function __construct(DCAOffsetModeEnum $offsetMode = DCAOffsetModeEnum::FROM_ENTRY) {
		$this->offsetMode = $offsetMode;
	}

	/**
	 * Add a level to the grid.
	 *
	 * @param float $volume Order volume.
	 * @param float $offsetPercent Price offset percentage.
	 * @return self Fluent interface.
	 */
	public function addLevel(float $volume, float $offsetPercent): self {
		$this->levels[] = new DCAOrderLevel($volume, $offsetPercent);
		return $this;
	}

	/**
	 * Build a grid from DCA parameters (factory method).
	 *
	 * This method creates a grid using the traditional DCA parameters:
	 * - Number of levels
	 * - Entry volume with multiplier
	 * - Price deviation with multiplier
	 *
	 * @param int $numberOfLevels Number of DCA levels including entry.
	 * @param float $entryVolume Initial entry volume.
	 * @param float $volumeMultiplier Multiplier for each subsequent order volume.
	 * @param float $priceDeviation Initial price deviation percentage.
	 * @param float $priceDeviationMultiplier Multiplier for each subsequent deviation.
	 * @param DCAOffsetModeEnum $offsetMode Offset calculation mode.
	 * @return self
	 */
	public static function fromParameters(
		int $numberOfLevels,
		float $entryVolume,
		float $volumeMultiplier,
		float $priceDeviation,
		float $priceDeviationMultiplier,
		DCAOffsetModeEnum $offsetMode = DCAOffsetModeEnum::FROM_ENTRY
	): self {
		$grid = new self($offsetMode);

		$volume = $entryVolume;
		$currentDeviation = 0.0; // Entry level has no offset

		for ($level = 0; $level < $numberOfLevels; $level++) {
			$grid->addLevel($volume, $currentDeviation);
			$volume *= $volumeMultiplier;

			// Calculate next deviation
			if ($level === 0) {
				$currentDeviation = $priceDeviation;
			} else {
				$currentDeviation *= $priceDeviationMultiplier;
			}
		}

		return $grid;
	}

	/**
	 * Get the offset calculation mode.
	 * @return DCAOffsetModeEnum
	 */
	public function getOffsetMode(): DCAOffsetModeEnum {
		return $this->offsetMode;
	}

	/**
	 * Set the offset calculation mode.
	 *
	 * @param DCAOffsetModeEnum $offsetMode New offset mode.
	 * @return self Fluent interface.
	 */
	public function setOffsetMode(DCAOffsetModeEnum $offsetMode): self {
		$this->offsetMode = $offsetMode;
		return $this;
	}

	/**
	 * Build the order map with absolute offsets from entry price.
	 *
	 * This method converts the grid levels into an order map format compatible
	 * with the existing Market::openPositionByLimitOrderMap() method.
	 *
	 * The offset values in the result are always relative to the entry price,
	 * regardless of the offset mode. The mode affects how the offsets are calculated.
	 *
	 * @param PositionDirectionEnum $direction Position direction (LONG or SHORT).
	 * @return array Array of ['volume' => float, 'offset' => float] entries.
	 */
	public function buildOrderMap(PositionDirectionEnum $direction): array {
		$orderMap = [];
		$sign = $direction->isLong() ? -1 : 1;

		if ($this->offsetMode->isFromEntry()) {
			// Traditional mode: offsets accumulate from entry price
			$totalOffset = 0.0;

			foreach ($this->levels as $index => $level) {
				$orderMap[$index] = [
					'volume' => $level->getVolume(),
					'offset' => $sign * $totalOffset,
				];
				$totalOffset += $level->getOffsetPercent();
			}
		} else {
			// FROM_PREVIOUS mode: each offset is relative to the previous order's price
			// We need to convert these relative offsets to absolute offsets from entry
			$absoluteOffset = 0.0;

			foreach ($this->levels as $index => $level) {
				$orderMap[$index] = [
					'volume' => $level->getVolume(),
					'offset' => $sign * $absoluteOffset,
				];

				// Calculate the next absolute offset
				// If current price is at (100 - absoluteOffset)% of entry,
				// and we want to go down by offsetPercent% from current,
				// new price = currentPrice * (1 - offsetPercent/100)
				// new absolute offset = 100 - (100 - absoluteOffset) * (1 - offsetPercent/100)
				$relativeMultiplier = (100 - $absoluteOffset) / 100;
				$absoluteOffset += $level->getOffsetPercent() * $relativeMultiplier;
			}
		}

		return $orderMap;
	}

	/**
	 * Get all levels in the grid.
	 * @return DCAOrderLevel[]
	 */
	public function getLevels(): array {
		return $this->levels;
	}

	/**
	 * Get the number of levels in the grid.
	 * @return int
	 */
	public function count(): int {
		return count($this->levels);
	}

	/**
	 * Get the total volume of all orders in the grid.
	 * @return float
	 */
	public function getTotalVolume(): float {
		$total = 0.0;
		foreach ($this->levels as $level) {
			$total += $level->getVolume();
		}
		return $total;
	}

	/**
	 * Check if the grid is empty.
	 * @return bool
	 */
	public function isEmpty(): bool {
		return empty($this->levels);
	}
}
