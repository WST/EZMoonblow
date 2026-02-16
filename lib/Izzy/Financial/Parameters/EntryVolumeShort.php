<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class EntryVolumeShort extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'entryVolumeShort';
	}

	public function getLabel(): string {
		return 'Entry volume (Short)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::STRING;
	}

	protected function getClassDefault(): string {
		return '10';
	}
}
