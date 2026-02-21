<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class InitialEntryVolumeShort extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'initialEntryVolumeShort';
	}

	public function getLabel(): string {
		return 'Initial Entry volume (Short)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::STRING;
	}

	public function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '10';
	}
}
