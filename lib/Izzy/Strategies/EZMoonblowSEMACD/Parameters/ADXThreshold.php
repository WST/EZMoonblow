<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ADXThreshold extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'adxThreshold';
	}

	public function getLabel(): string {
		return 'ADX threshold';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'adxFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return '20';
	}
}
