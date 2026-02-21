<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class UseIsolatedMargin extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'useIsolatedMargin';
	}

	public function getLabel(): string {
		return 'Use isolated margin';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return 'true';
	}

	public function isBacktestRelevant(): bool {
		return false;
	}
}
