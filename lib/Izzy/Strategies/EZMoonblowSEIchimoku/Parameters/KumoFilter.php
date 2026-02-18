<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class KumoFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'kumoFilter';
	}

	public function getLabel(): string {
		return 'Kumo (cloud) position filter';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'signalType', 'value' => 'tk_cross'];
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'When enabled, TK Cross longs require price above the cloud, shorts require price below. Reduces false signals in ranging markets.';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}
