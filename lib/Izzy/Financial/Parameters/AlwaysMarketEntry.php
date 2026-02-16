<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class AlwaysMarketEntry extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'alwaysMarketEntry';
	}

	public function getLabel(): string {
		return 'Always execute entry order as market';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	protected function getClassDefault(): string {
		return 'true';
	}
}
