<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class EntryVolume extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'entryVolume';
	}

	public function getLabel(): string {
		return 'Entry volume (USDT, %, %M, or base currency)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::STRING;
	}

	public function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '100%';
	}
}
