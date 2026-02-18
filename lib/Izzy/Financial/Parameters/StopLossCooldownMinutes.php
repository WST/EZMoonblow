<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class StopLossCooldownMinutes extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'stopLossCooldownMinutes';
	}

	public function getLabel(): string {
		return 'Cooldown after stop-loss (min)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'Single Entry';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'After a stop-loss hit, wait this many minutes before opening a new position. 0 = no cooldown.';
	}

	protected function getClassDefault(): string {
		return '0';
	}
}
