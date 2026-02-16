<?php

namespace Izzy\Strategies\EZMoonblowDCAAlwaysLong;

use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Indicators\RSI;

class EZMoonblowDCAAlwaysLong extends AbstractDCAStrategy
{
	public static function getDisplayName(): string {
		return 'Always-Long DCA';
	}

	public function useIndicators(): array {
		return [RSI::class];
	}

	/**
	 * In this custom strategy, we always long. Always!
	 * @return bool
	 */
	public function shouldLong(): bool {
		return true;
	}

	public function doesLong(): bool {
		return true;
	}

	public function doesShort(): bool {
		return false;
	}
}
