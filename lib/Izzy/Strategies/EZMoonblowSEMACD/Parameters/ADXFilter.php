<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ADXFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'adxFilter';
	}

	public function getLabel(): string {
		return 'ADX trend strength filter';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'When enabled, entries are only allowed when ADX is above the threshold, indicating a trending market.';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}
