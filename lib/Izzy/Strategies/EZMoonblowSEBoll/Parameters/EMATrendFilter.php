<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class EMATrendFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'emaTrendFilter';
	}

	public function getLabel(): string {
		return 'EMA daily trend filter';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}
