<?php

namespace Izzy\Strategies\EZMoonblowDCAAlwaysLong;

use Izzy\Financial\AbstractDCAStrategy;

class EZMoonblowDCAAlwaysLong extends AbstractDCAStrategy
{
	public static function getDisplayName(): string {
		return 'Always-Long DCA';
	}

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
