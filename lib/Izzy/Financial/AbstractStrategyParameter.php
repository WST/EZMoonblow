<?php

namespace Izzy\Financial;

use Izzy\Enums\StrategyParameterTypeEnum;

/**
 * Base class for typed strategy configuration parameters.
 * Each parameter knows its config key, human-readable label, data type,
 * and default value. SELECT parameters also provide available options.
 */
abstract class AbstractStrategyParameter
{
	/**
	 * @param string|null $defaultOverride Override the default value defined by the parameter class.
	 */
	public function __construct(private ?string $defaultOverride = null) {
	}

	/**
	 * Config key as used in XML/params array (e.g. "stopLossPercent").
	 */
	abstract public function getName(): string;

	/**
	 * Human-readable label for the UI (e.g. "Stop-loss distance (%)").
	 */
	abstract public function getLabel(): string;

	/**
	 * Data type of the parameter value.
	 */
	abstract public function getType(): StrategyParameterTypeEnum;

	/**
	 * Default value defined by the parameter class.
	 */
	abstract protected function getClassDefault(): string;

	/**
	 * Effective default value (respects constructor override).
	 */
	public function getDefault(): string {
		return $this->defaultOverride ?? $this->getClassDefault();
	}

	/**
	 * Available options for SELECT parameters.
	 * Override in subclasses that use StrategyParameterTypeEnum::SELECT.
	 *
	 * @return array<string, string> value => label.
	 */
	public function getOptions(): array {
		return [];
	}

	/**
	 * Serialize to a plain array suitable for JSON API responses.
	 *
	 * @return array{key: string, label: string, type: string, default: string, options?: array<string, string>}
	 */
	public function toArray(): array {
		$data = [
			'key' => $this->getName(),
			'label' => $this->getLabel(),
			'type' => $this->getType()->value,
			'default' => $this->getDefault(),
		];
		if ($this->getType()->isSelect()) {
			$data['options'] = $this->getOptions();
		}
		return $data;
	}
}
