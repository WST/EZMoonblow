<?php

namespace Izzy\Strategies\EZMoonblowDCAWithShorts;

use Izzy\Financial\AbstractStrategyParameter;
use Izzy\Financial\Parameters\InitialEntryVolumeShort;
use Izzy\Financial\Parameters\ExpectedProfitShort;
use Izzy\Financial\Parameters\NumberOfLevelsShort;
use Izzy\Financial\Parameters\PriceDeviationMultiplierShort;
use Izzy\Financial\Parameters\PriceDeviationShort;
use Izzy\Financial\Parameters\VolumeMultiplierShort;
use Izzy\Strategies\EZMoonblowDCA\EZMoonblowDCA;

/**
 * Alternative EZMoonblowDCA implementation that adds support for Short positions.
 */
class EZMoonblowDCAWithShorts extends EZMoonblowDCA
{
	public static function getDisplayName(): string {
		return 'RSI DCA (Long & Short)';
	}

	public function shouldShort(): bool {
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');
		return $rsiSignal === 'overbought';
	}

	public function doesLong(): bool {
		return true;
	}

	public function doesShort(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 * @return AbstractStrategyParameter[]
	 */
	public static function getParameters(): array {
		return array_merge(parent::getParameters(), [
			new NumberOfLevelsShort(),
			new InitialEntryVolumeShort('1%'),
			new VolumeMultiplierShort(),
			new PriceDeviationShort(),
			new PriceDeviationMultiplierShort(),
			new ExpectedProfitShort(),
		]);
	}
}

