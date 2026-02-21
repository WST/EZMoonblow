<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PartialClosePercent extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'partialClosePercent';
	}

	public function getLabel(): string {
		return 'Partial Close portion (%)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '70';
	}

	public function getEnabledCondition(): ?array {
		return [
			'paramKey' => 'partialCloseEnabled',
			'value' => 'true',
		];
	}
}
