<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class OffsetMode extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'offsetMode';
	}

	public function getLabel(): string {
		return 'Price offset calculation mode';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public function getGroup(): string {
		return 'DCA';
	}

	protected function getClassDefault(): string {
		return DCAOffsetModeEnum::FROM_PREVIOUS->value;
	}

	public function getOptions(): array {
		$options = [];
		foreach (DCAOffsetModeEnum::cases() as $case) {
			$options[$case->value] = $case->getDescription();
		}
		return $options;
	}
}
