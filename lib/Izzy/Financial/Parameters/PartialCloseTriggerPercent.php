<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class PartialCloseTriggerPercent extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'partialCloseTriggerPercent';
	}

	public function getLabel(): string {
		return 'Partial Close trigger (% of way to TP)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return 'Single Entry';
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
