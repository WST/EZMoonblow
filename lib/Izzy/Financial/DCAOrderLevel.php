<?php

namespace Izzy\Financial;

use Izzy\Enums\EntryVolumeModeEnum;

/**
 * Represents a single level in a DCA order grid.
 */
class DCAOrderLevel
{
	/**
	 * Raw volume value at this level.
	 * Interpretation depends on volumeMode.
	 * @var float
	 */
	private float $volume;

	/**
	 * Price offset percentage for this level.
	 * @var float
	 */
	private float $offsetPercent;

	/**
	 * Volume interpretation mode.
	 * @var EntryVolumeModeEnum
	 */
	private EntryVolumeModeEnum $volumeMode;

	/**
	 * Creates a new DCA order level.
	 *
	 * @param float $volume Raw volume value (interpretation depends on volumeMode).
	 * @param float $offsetPercent Price offset percentage (relative interpretation depends on grid mode).
	 * @param EntryVolumeModeEnum $volumeMode How volume should be interpreted.
	 */
	public function __construct(
		float $volume,
		float $offsetPercent,
		EntryVolumeModeEnum $volumeMode = EntryVolumeModeEnum::ABSOLUTE_QUOTE
	) {
		$this->volume = $volume;
		$this->offsetPercent = $offsetPercent;
		$this->volumeMode = $volumeMode;
	}

	/**
	 * Get the raw volume value.
	 * @return float
	 */
	public function getVolume(): float {
		return $this->volume;
	}

	/**
	 * Get the price offset percentage.
	 * @return float
	 */
	public function getOffsetPercent(): float {
		return $this->offsetPercent;
	}

	/**
	 * Get the volume mode.
	 * @return EntryVolumeModeEnum
	 */
	public function getVolumeMode(): EntryVolumeModeEnum {
		return $this->volumeMode;
	}

	/**
	 * Calculate the actual volume in quote currency (USDT) based on mode.
	 *
	 * @param TradingContext $context Runtime trading context.
	 * @return float Volume in quote currency (USDT).
	 */
	public function resolveVolume(TradingContext $context): float {
		return match ($this->volumeMode) {
			EntryVolumeModeEnum::ABSOLUTE_QUOTE => $this->volume,
			EntryVolumeModeEnum::PERCENT_BALANCE => $context->getBalance() * ($this->volume / 100),
			EntryVolumeModeEnum::PERCENT_MARGIN => $context->getMargin() * ($this->volume / 100),
			EntryVolumeModeEnum::ABSOLUTE_BASE => $this->volume * $context->getCurrentPrice()->getAmount(),
		};
	}

	/**
	 * Convert level to array representation.
	 * @return array
	 */
	public function toArray(): array {
		return [
			'volume' => $this->volume,
			'offsetPercent' => $this->offsetPercent,
			'volumeMode' => $this->volumeMode->value,
		];
	}
}
