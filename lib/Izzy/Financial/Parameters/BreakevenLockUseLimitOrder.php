<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BreakevenLockUseLimitOrder extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'breakevenLockUseLimitOrder';
	}

	public function getLabel(): string {
		return 'Breakeven Lock via limit order';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'Single Entry';
	}

	protected function getClassDefault(): string {
		return 'false';
	}

	public function getEnabledCondition(): ?array {
		return [
			'paramKey' => 'breakevenLockEnabled',
			'value' => 'true',
		];
	}
}
