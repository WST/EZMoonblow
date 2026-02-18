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
	 * Human-readable group name for fieldset grouping in the UI.
	 */
	abstract public function getGroup(): string;

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
	 * Whether to show a question mark icon with a tooltip next to the label.
	 */
	public function hasQuestionMark(): bool {
		return false;
	}

	/**
	 * Tooltip text for the question mark icon.
	 */
	public function getQuestionMarkTooltip(): string {
		return '';
	}

	/**
	 * Whether to show a red exclamation mark icon with a tooltip next to the label.
	 */
	public function hasExclamationMark(): bool {
		return false;
	}

	/**
	 * Tooltip text for the exclamation mark icon.
	 */
	public function getExclamationMarkTooltip(): string {
		return '';
	}

	/**
	 * Whether this parameter affects backtest simulation.
	 * Parameters that only matter for live trading (e.g. margin mode,
	 * order type) should return false so the backtest UI can hide them.
	 */
	public function isBacktestRelevant(): bool {
		return true;
	}

	/**
	 * Declarative dependency: this parameter is only enabled when another
	 * parameter has a specific value. Override in subclasses to declare.
	 *
	 * @return array{paramKey: string, value: string}|null Null = always enabled.
	 */
	public function getEnabledCondition(): ?array {
		return null;
	}

	/**
	 * Normalize a raw config value to a canonical string form.
	 * Used for smart comparison between configs (e.g. "yes" == "true" for bools).
	 *
	 * @param mixed $value Raw value from XML or backtest params.
	 * @return string Canonical string representation.
	 */
	public function normalizeValue(mixed $value): string {
		$str = (string)$value;
		return match ($this->getType()) {
			StrategyParameterTypeEnum::BOOL => self::normalizeBool($str),
			StrategyParameterTypeEnum::INT => (string)(int)$str,
			StrategyParameterTypeEnum::FLOAT => rtrim(rtrim(number_format((float)$str, 10, '.', ''), '0'), '.'),
			default => $str,
		};
	}

	private static function normalizeBool(string $value): string {
		return in_array(strtolower($value), ['true', 'yes', '1'], true) ? 'true' : 'false';
	}

	/**
	 * Serialize to a plain array suitable for JSON API responses.
	 *
	 * @return array{key: string, label: string, type: string, default: string, group: string, options?: array<string, string>}
	 */
	public function toArray(): array {
		$data = [
			'key' => $this->getName(),
			'label' => $this->getLabel(),
			'type' => $this->getType()->value,
			'default' => $this->getDefault(),
			'group' => $this->getGroup(),
		];
		if ($this->getType()->isSelect()) {
			$data['options'] = $this->getOptions();
		}
		if ($this->hasQuestionMark()) {
			$data['questionMark'] = $this->getQuestionMarkTooltip();
		}
		if ($this->hasExclamationMark()) {
			$data['exclamationMark'] = $this->getExclamationMarkTooltip();
		}
		if (!$this->isBacktestRelevant()) {
			$data['backtestRelevant'] = false;
		}
		if ($condition = $this->getEnabledCondition()) {
			$data['enabledWhen'] = $condition;
		}
		return $data;
	}
}
