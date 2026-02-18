<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBOffset extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'bbOffset';
	}

	public function getLabel(): string {
		return 'BB penetration offset (%)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Price must move this % beyond the Bollinger Band before entry triggers. 0 = enter on band touch.';
	}

	protected function getClassDefault(): string {
		return '0';
	}
}
