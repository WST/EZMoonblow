<?php

namespace Izzy\Strategies;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\EntryVolumeModeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\TradingContext;

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
	 * @param float $volume Order volume (raw value, interpretation depends on volumeMode).
	 * @param float $offsetPercent Price offset percentage.
	 * @param EntryVolumeModeEnum $volumeMode How volume should be interpreted.
	 * @return self Fluent interface.
	 */
	public function addLevel(
		float $volume,
		float $offsetPercent,
		EntryVolumeModeEnum $volumeMode = EntryVolumeModeEnum::ABSOLUTE_QUOTE
	): self {
		$this->levels[] = new DCAOrderLevel($volume, $offsetPercent, $volumeMode);
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
	 * @param float $entryVolume Initial entry volume (raw value).
	 * @param float $volumeMultiplier Multiplier for each subsequent order volume.
	 * @param float $priceDeviation Initial price deviation percentage.
	 * @param float $priceDeviationMultiplier Multiplier for each subsequent deviation.
	 * @param DCAOffsetModeEnum $offsetMode Offset calculation mode.
	 * @param EntryVolumeModeEnum $volumeMode Volume interpretation mode.
	 * @return self
	 */
	public static function fromParameters(
		int $numberOfLevels,
		float $entryVolume,
		float $volumeMultiplier,
		float $priceDeviation,
		float $priceDeviationMultiplier,
		DCAOffsetModeEnum $offsetMode = DCAOffsetModeEnum::FROM_ENTRY,
		EntryVolumeModeEnum $volumeMode = EntryVolumeModeEnum::ABSOLUTE_QUOTE
	): self {
		$grid = new self($offsetMode);

		$volume = $entryVolume;
		$currentDeviation = $priceDeviation; // Initial deviation for first averaging

		for ($level = 0; $level < $numberOfLevels; $level++) {
			// Entry level (0) has no offset, subsequent levels use currentDeviation
			$levelOffset = ($level === 0) ? 0.0 : $currentDeviation;
			$grid->addLevel($volume, $levelOffset, $volumeMode);

			$volume *= $volumeMultiplier;

			// Apply multiplier for next level (only after first averaging)
			if ($level > 0) {
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
	 * @param TradingContext $context Runtime trading context for volume resolution.
	 * @return array Array of ['volume' => float, 'offset' => float] entries.
	 */
	public function buildOrderMap(PositionDirectionEnum $direction, TradingContext $context): array {
		$orderMap = [];
		$sign = $direction->isLong() ? -1 : 1;

		if ($this->offsetMode->isFromEntry()) {
			// Traditional mode: offsets accumulate from entry price
			$totalOffset = 0.0;

			foreach ($this->levels as $index => $level) {
				// First accumulate the offset, then record it
				$totalOffset += $level->getOffsetPercent();

				// Resolve volume based on mode
				$resolvedVolume = $level->resolveVolume($context);

				$orderMap[$index] = [
					'volume' => $resolvedVolume,
					'offset' => $sign * $totalOffset,
				];
			}
		} else {
			// FROM_PREVIOUS mode: each offset is relative to the previous order's price
			// We need to convert these relative offsets to absolute offsets from entry
			// The calculation differs based on direction:
			// - LONG: price goes DOWN, each step reduces price by stepPercent%
			// - SHORT: price goes UP, each step increases price by stepPercent%
			$currentPriceRatio = 1.0; // Start at entry price (100%)

			foreach ($this->levels as $index => $level) {
				$stepPercent = $level->getOffsetPercent();

				if ($stepPercent > 0) {
					if ($direction->isLong()) {
						// Long: price drops by stepPercent% from current level
						// newPrice = currentPrice * (1 - step/100)
						$currentPriceRatio *= (1 - $stepPercent / 100);
					} else {
						// Short: price rises by stepPercent% from current level
						// newPrice = currentPrice * (1 + step/100)
						$currentPriceRatio *= (1 + $stepPercent / 100);
					}
				}

				// Calculate absolute offset from entry price
				// For Long: offset = (1 - priceRatio) * 100, negative (price below entry)
				// For Short: offset = (priceRatio - 1) * 100, positive (price above entry)
				$absoluteOffset = $direction->isLong()
					? (1 - $currentPriceRatio) * 100
					: ($currentPriceRatio - 1) * 100;

				// Resolve volume based on mode
				$resolvedVolume = $level->resolveVolume($context);

				$orderMap[$index] = [
					'volume' => $resolvedVolume,
					'offset' => $sign * $absoluteOffset,
				];
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
	 * Get the total volume of all orders in the grid (resolved to quote currency).
	 *
	 * @param TradingContext $context Runtime trading context for volume resolution.
	 * @return float Total volume in quote currency.
	 */
	public function getTotalVolume(TradingContext $context): float {
		$total = 0.0;
		foreach ($this->levels as $level) {
			$total += $level->resolveVolume($context);
		}
		return $total;
	}

	/**
	 * Get the raw total volume (without resolving modes).
	 * Useful for display when context is not available.
	 * @return float
	 */
	public function getRawTotalVolume(): float {
		$total = 0.0;
		foreach ($this->levels as $level) {
			$total += $level->getVolume();
		}
		return $total;
	}

	/**
	 * Check if any level requires runtime calculation.
	 * @return bool
	 */
	public function requiresRuntimeCalculation(): bool {
		foreach ($this->levels as $level) {
			if ($level->getVolumeMode()->requiresRuntimeCalculation()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if the grid is empty.
	 * @return bool
	 */
	public function isEmpty(): bool {
		return empty($this->levels);
	}
}
