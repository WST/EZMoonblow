<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BreakevenLockClosePercent extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'breakevenLockClosePercent';
	}

	public function getLabel(): string {
		return 'Breakeven Lock close portion (%)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '25';
	}
}
