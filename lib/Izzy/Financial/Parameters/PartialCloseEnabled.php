<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PartialCloseEnabled extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'partialCloseEnabled';
	}

	public function getLabel(): string {
		return 'Partial Close enabled';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}
