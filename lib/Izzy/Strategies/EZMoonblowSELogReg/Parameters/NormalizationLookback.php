<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class NormalizationLookback extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'normalizationLookback';
	}

	public function getLabel(): string {
		return 'Normalization lookback';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Period for minimax normalization of loss and prediction into the price range. '
			. 'Small values (2-5) make signals noisy; larger values (50-120) produce more stable signals.';
	}

	protected function getClassDefault(): string {
		return '50';
	}
}
