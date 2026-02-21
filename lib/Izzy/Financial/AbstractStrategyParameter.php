<?php

namespace Izzy\Financial;

use Izzy\Enums\StrategyParameterTypeEnum;

/**
 * Base class for typed strategy configuration parameters.
 * Each parameter knows its config key, human-readable label, data type,
 * and default value. SELECT parameters also provide available options.
 *
 * Instances can hold a runtime value via the static factory from().
 */
abstract class AbstractStrategyParameter
{
	/** Runtime value (string form). Null means "not set, use default". */
	private ?string $value = null;

	/**
	 * @param string|null $defaultOverride Override the default value defined by the parameter class.
	 */
	public function __construct(private ?string $defaultOverride = null) {
	}

	/**
	 * Create a value-holding instance from a raw config/XML/API value.
	 *
	 * @param mixed $rawValue Raw value (string, int, float, bool, or null for default).
	 * @return static Instance with the value set.
	 */
	public static function from(mixed $rawValue = null): static {
		$instance = new static();
		$instance->value = ($rawValue !== null) ? (string)$rawValue : static::getClassDefault();
		return $instance;
	}

	/**
	 * Get the typed runtime value.
	 *
	 * @return int|float|bool|string Value cast according to getType().
	 */
	public function getValue(): int|float|bool|string {
		$raw = $this->getRawValue();
		return match (static::getType()) {
			StrategyParameterTypeEnum::INT => (int)$raw,
			StrategyParameterTypeEnum::FLOAT => (float)$raw,
			StrategyParameterTypeEnum::BOOL => in_array(strtolower($raw), ['true', 'yes', '1'], true),
			default => $raw,
		};
	}

	/**
	 * Get the raw string value (for serialization to JSON/DB/XML).
	 */
	public function getRawValue(): string {
		return $this->value ?? $this->getDefault();
	}

	/**
	 * Whether this instance holds a runtime value (was created via from()).
	 */
	public function hasValue(): bool {
		return $this->value !== null;
	}

	/**
	 * Compare two parameter instances by their normalized values.
	 */
	public function equals(self $other): bool {
		return static::getName() === $other::getName()
			&& $this->normalizeValue($this->getRawValue()) === $other->normalizeValue($other->getRawValue());
	}

	// ── Metadata (abstract, must be implemented by subclasses) ──────────

	/**
	 * Config key used in XML/params arrays.
	 * Derived from the short class name (e.g. StopLossPercent → "StopLossPercent").
	 */
	public static function getName(): string {
		$fqcn = static::class;
		$pos = strrpos($fqcn, '\\');
		return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
	}

	/**
	 * Human-readable label for the UI (e.g. "Stop-loss distance (%)").
	 */
	abstract public static function getLabel(): string;

	/**
	 * Data type of the parameter value.
	 */
	abstract public static function getType(): StrategyParameterTypeEnum;

	/**
	 * Default value defined by the parameter class.
	 */
	abstract protected static function getClassDefault(): string;

	/**
	 * Human-readable group name for fieldset grouping in the UI.
	 */
	abstract public static function getGroup(): string;

	/**
	 * Effective default value (respects constructor override).
	 */
	public function getDefault(): string {
		return $this->defaultOverride ?? static::getClassDefault();
	}

	/**
	 * Available options for SELECT parameters.
	 * Override in subclasses that use StrategyParameterTypeEnum::SELECT.
	 *
	 * @return array<string, string> value => label.
	 */
	public static function getOptions(): array {
		return [];
	}

	/**
	 * Whether to show a question mark icon with a tooltip next to the label.
	 */
	public static function hasQuestionMark(): bool {
		return false;
	}

	/**
	 * Tooltip text for the question mark icon.
	 */
	public static function getQuestionMarkTooltip(): string {
		return '';
	}

	/**
	 * Whether to show a red exclamation mark icon with a tooltip next to the label.
	 */
	public static function hasExclamationMark(): bool {
		return false;
	}

	/**
	 * Tooltip text for the exclamation mark icon.
	 */
	public static function getExclamationMarkTooltip(): string {
		return '';
	}

	/**
	 * Whether this parameter affects backtest simulation.
	 * Parameters that only matter for live trading (e.g. margin mode,
	 * order type) should return false so the backtest UI can hide them.
	 */
	public static function isBacktestRelevant(): bool {
		return true;
	}

	/**
	 * Declarative dependency: this parameter is only enabled when another
	 * parameter has a specific value. Override in subclasses to declare.
	 *
	 * @return array{paramKey: string, value: string}|null Null = always enabled.
	 */
	public static function getEnabledCondition(): ?array {
		return null;
	}

	// ── Mutation (Optimizer) ────────────────────────────────────────────

	/**
	 * Produce a mutated value for the optimizer.
	 *
	 * Each type knows how to mutate itself:
	 *   - BOOL flips to the opposite value.
	 *   - INT shifts by ±1, minimum 0 (0 can only become 1).
	 *   - FLOAT shifts by ±10% of the current value, minimum 0.
	 *   - SELECT picks a random different option.
	 *
	 * Subclasses may override for domain-specific constraints.
	 *
	 * @param string $currentValue Current parameter value.
	 * @return string Mutated value (may equal $currentValue if mutation is impossible).
	 */
	public function mutate(string $currentValue): string {
		return match (static::getType()) {
			StrategyParameterTypeEnum::BOOL => self::mutateBool($currentValue),
			StrategyParameterTypeEnum::INT => self::mutateInt($currentValue),
			StrategyParameterTypeEnum::FLOAT => self::mutateFloat($currentValue),
			StrategyParameterTypeEnum::SELECT => static::mutateSelect($currentValue),
			default => $currentValue,
		};
	}

	private static function mutateBool(string $value): string {
		return in_array(strtolower($value), ['true', 'yes', '1'], true) ? 'false' : 'true';
	}

	private static function mutateInt(string $value): string {
		$v = (int) $value;
		if ($v <= 0) {
			return '1';
		}
		$direction = random_int(0, 1) === 0 ? -1 : 1;
		return (string) max(0, $v + $direction);
	}

	private static function mutateFloat(string $value): string {
		$v = (float) $value;
		$direction = random_int(0, 1) === 0 ? -1.0 : 1.0;
		$shift = abs($v) * 0.1 * (mt_rand(50, 100) / 100.0);
		$mutated = $v + $direction * $shift;
		if ($mutated === $v) {
			$mutated = $v + $direction * max(0.0001, abs($v) * 0.01);
		}
		return (string) round(max(0.0, $mutated), 4);
	}

	private static function mutateSelect(string $value): string {
		$options = array_keys(static::getOptions());
		if (count($options) <= 1) {
			return $value;
		}
		$others = array_values(array_filter($options, fn(string $o) => $o !== $value));
		return $others[array_rand($others)];
	}

	// ── Normalization ───────────────────────────────────────────────────

	/**
	 * Normalize a raw config value to a canonical string form.
	 * Used internally by equals() for comparison.
	 *
	 * @param mixed $value Raw value from XML or backtest params.
	 * @return string Canonical string representation.
	 */
	public function normalizeValue(mixed $value): string {
		$str = (string)$value;
		return match (static::getType()) {
			StrategyParameterTypeEnum::BOOL => self::normalizeBool($str),
			StrategyParameterTypeEnum::INT => (string)(int)$str,
			StrategyParameterTypeEnum::FLOAT => rtrim(rtrim(number_format((float)$str, 10, '.', ''), '0'), '.'),
			default => $str,
		};
	}

	private static function normalizeBool(string $value): string {
		return in_array(strtolower($value), ['true', 'yes', '1'], true) ? 'true' : 'false';
	}

	// ── Serialization ───────────────────────────────────────────────────

	/**
	 * Serialize to a plain array suitable for JSON API responses.
	 *
	 * @return array{key: string, label: string, type: string, default: string, group: string, options?: array<string, string>}
	 */
	public function toArray(): array {
		$data = [
			'key' => static::getName(),
			'label' => static::getLabel(),
			'type' => static::getType()->value,
			'default' => $this->getDefault(),
			'group' => static::getGroup(),
		];
		if (static::getType()->isSelect()) {
			$data['options'] = static::getOptions();
		}
		if (static::hasQuestionMark()) {
			$data['questionMark'] = static::getQuestionMarkTooltip();
		}
		if (static::hasExclamationMark()) {
			$data['exclamationMark'] = static::getExclamationMarkTooltip();
		}
		if (!static::isBacktestRelevant()) {
			$data['backtestRelevant'] = false;
		}
		if ($condition = static::getEnabledCondition()) {
			$data['enabledWhen'] = $condition;
		}
		return $data;
	}
}
