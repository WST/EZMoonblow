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

	public function getGroup(): string {
		return 'Single Entry';
	}

	protected function getClassDefault(): string {
		return '25';
	}

	public function getEnabledCondition(): ?array {
		return [
			'paramKey' => BreakevenLockEnabled::getName(),
			'value' => 'true'
		];
	}
}
