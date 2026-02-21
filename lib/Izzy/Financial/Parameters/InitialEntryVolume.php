<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

/**
 * Initial entry volume for DCA strategies.
 * Uses the same config key as EntryVolume ("entryVolume") for backward compatibility,
 * but belongs to the DCA parameter group and has a DCA-appropriate default.
 */
class InitialEntryVolume extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'initialEntryVolume';
	}

	public function getLabel(): string {
		return 'Initial entry volume (USDT, %, %M, or base currency)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::STRING;
	}

	public function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '3%';
	}
}
