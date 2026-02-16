<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class VolumeMultiplier extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'volumeMultiplier';
	}

	public function getLabel(): string {
		return 'Volume multiplier for each subsequent order';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '1.473';
	}
}
