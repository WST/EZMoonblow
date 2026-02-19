<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopeFilterAction extends AbstractStrategyParameter
{
	public const string BLOCK = 'block';
	public const string INVERSE = 'inverse';

	public function getName(): string {
		return 'bbSlopeFilterAction';
	}

	public function getLabel(): string {
		return 'BB slope filter action';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function getOptions(): array {
		return [
			self::BLOCK => 'Block Entry',
			self::INVERSE => 'Inverse Entry',
		];
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Block Entry — reject the signal when the band is sloping steeply. '
			. 'Inverse Entry — enter in the opposite direction (short instead of long and vice versa) '
			. 'when the slope filter fires, treating it as a trend-following signal.';
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'bbSlopeFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return self::BLOCK;
	}
}
