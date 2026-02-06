<?php

namespace Izzy\Strategies;

/**
 * Represents a single level in a DCA order grid.
 */
class DCAOrderLevel {
	/**
	 * Order volume at this level.
	 * @var float
	 */
	private float $volume;

	/**
	 * Price offset percentage for this level.
	 * @var float
	 */
	private float $offsetPercent;

	/**
	 * Creates a new DCA order level.
	 *
	 * @param float $volume Order volume at this level.
	 * @param float $offsetPercent Price offset percentage (relative interpretation depends on grid mode).
	 */
	public function __construct(float $volume, float $offsetPercent) {
		$this->volume = $volume;
		$this->offsetPercent = $offsetPercent;
	}

	/**
	 * Get the order volume.
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
	 * Create a level from an array representation.
	 *
	 * @param array $data Array with 'volume' and 'offsetPercent' keys.
	 * @return self
	 */
	public static function fromArray(array $data): self {
		return new self(
			$data['volume'] ?? 0.0,
			$data['offsetPercent'] ?? 0.0
		);
	}

	/**
	 * Convert level to array representation.
	 * @return array
	 */
	public function toArray(): array {
		return [
			'volume' => $this->volume,
			'offsetPercent' => $this->offsetPercent,
		];
	}
}
