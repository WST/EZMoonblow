<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BreakevenLockEnabled extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'breakevenLockEnabled';
	}

	public function getLabel(): string {
		return 'Breakeven Lock enabled';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'Single Entry';
	}

	protected function getClassDefault(): string {
		return 'true';
	}
}
