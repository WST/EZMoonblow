<?php

namespace Izzy\Strategies;

use Izzy\Financial\Money;
use Izzy\Indicators\RSI;
use Izzy\System\Logger;

class EZMoonblowDCA extends AbstractDCAStrategy
{
	const float DEFAULT_ENTRY_VOLUME = 50;

	public function useIndicators(): array {
		return [RSI::class];
	}

	/**
	 * Get the entry volume (in USDT for USDT pairs) from the configuration file.
	 * @return Money
	 */
	protected function getEntryVolume(): Money {
		$entryVolume = $this->getParam('entryVolume');
		if (!$entryVolume) {
			Logger::getLogger()->debug("Entry volume is not set, defaulting to ".self::DEFAULT_ENTRY_VOLUME." USDT");
			$entryVolume = self::DEFAULT_ENTRY_VOLUME;
		}
		return Money::from($entryVolume);
	}

	/**
	 * In this custom strategy, we will buy when the price is low.
	 * @return bool
	 */
	public function shouldLong(): bool {
		// Get RSI signal.
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');

		// Buy when RSI shows oversold condition.
		return $rsiSignal === 'oversold';
	}

	public function doesLong(): bool {
		return true;
	}

	public function doesShort(): bool {
		return false;
	}
}
