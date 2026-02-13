<?php

namespace Izzy\Enums;

/**
 * Margin mode used for a futures position.
 */
enum MarginModeEnum: string
{
	/**
	 * Cross margin: the entire account balance is used as collateral.
	 */
	case CROSS = 'CROSS';

	/**
	 * Isolated margin: only the allocated margin is used as collateral.
	 */
	case ISOLATED = 'ISOLATED';

	/**
	 * Check if this is cross margin mode.
	 */
	public function isCross(): bool {
		return $this === self::CROSS;
	}

	/**
	 * Check if this is isolated margin mode.
	 */
	public function isIsolated(): bool {
		return $this === self::ISOLATED;
	}

	/**
	 * Get human-readable label for the UI.
	 */
	public function getLabel(): string {
		return match ($this) {
			self::CROSS => 'Cross',
			self::ISOLATED => 'Isolated',
		};
	}
}
