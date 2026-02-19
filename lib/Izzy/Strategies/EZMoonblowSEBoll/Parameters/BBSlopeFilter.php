<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopeFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'bbSlopeFilter';
	}

	public function getLabel(): string {
		return 'BB slope filter';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Reject entries when the relevant Bollinger Band is sloping steeply, indicating a strong trend where mean-reversion is unlikely to succeed.';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}
