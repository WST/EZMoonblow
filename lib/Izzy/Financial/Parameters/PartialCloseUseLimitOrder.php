<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class PartialCloseUseLimitOrder extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'partialCloseUseLimitOrder';
	}

	public function getLabel(): string {
		return 'Partial Close via limit order';
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
			'paramKey' => 'partialCloseEnabled',
			'value' => 'true',
		];
	}
}
