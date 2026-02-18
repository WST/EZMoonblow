<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ChikouFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'chikouFilter';
	}

	public function getLabel(): string {
		return 'Chikou Span confirmation';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'When enabled, longs require the current close to be above the close from displacement bars ago, and shorts require it to be below. Confirms momentum direction.';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}
