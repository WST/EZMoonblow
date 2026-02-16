<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BreakevenLockTriggerPercent extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'breakevenLockTriggerPercent';
	}

	public function getLabel(): string {
		return 'Breakeven Lock trigger (% of way to TP)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '10';
	}
}
