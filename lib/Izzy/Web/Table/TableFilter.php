<?php

namespace Izzy\Web\Table;

use Izzy\Enums\FilterFieldTypeEnum;
use Psr\Http\Message\ServerRequestInterface as Request;

class TableFilter
{
	/** @var TableFilterField[] */
	private array $fields = [];
	private array $values = [];

	public function addMultiSelect(string $key, string $label, array $options): self {
		$this->fields[] = new TableFilterField($key, $label, FilterFieldTypeEnum::MULTI_SELECT, $options);
		return $this;
	}

	public function addSelect(string $key, string $label, array $options): self {
		$this->fields[] = new TableFilterField($key, $label, FilterFieldTypeEnum::SELECT, $options);
		return $this;
	}

	public function addNumberInput(string $key, string $label, ?string $placeholder = null): self {
		$this->fields[] = new TableFilterField($key, $label, FilterFieldTypeEnum::NUMBER_INPUT, [], $placeholder);
		return $this;
	}

	/**
	 * Add a date condition filter (before / on / after a given date).
	 * The value is stored as "operator:YYYY-MM-DD" string.
	 */
	public function addDateCondition(string $key, string $label): self {
		$this->fields[] = new TableFilterField($key, $label, FilterFieldTypeEnum::DATE_CONDITION);
		return $this;
	}

	/**
	 * Create a populated filter from the request query parameters.
	 */
	public static function fromRequest(Request $request, self $template): self {
		$params = $request->getQueryParams();
		$filter = clone $template;

		foreach ($filter->fields as $field) {
			$raw = $params[$field->key] ?? null;
			if ($raw === null || $raw === '' || $raw === []) {
				$filter->values[$field->key] = null;
				continue;
			}

			if ($field->type === FilterFieldTypeEnum::MULTI_SELECT) {
				if (is_string($raw)) {
					$filter->values[$field->key] = array_filter(explode(',', $raw), fn($v) => $v !== '');
				} else {
					$filter->values[$field->key] = (array)$raw;
				}
			} elseif ($field->type === FilterFieldTypeEnum::NUMBER_INPUT) {
				$filter->values[$field->key] = is_numeric($raw) ? (float)$raw : null;
			} elseif ($field->type === FilterFieldTypeEnum::DATE_CONDITION) {
				$filter->values[$field->key] = self::parseDateCondition($raw);
			} else {
				$filter->values[$field->key] = (string)$raw;
			}
		}

		return $filter;
	}

	public function getValue(string $key): mixed {
		return $this->values[$key] ?? null;
	}

	/** @return TableFilterField[] */
	public function getFields(): array {
		return $this->fields;
	}

	/**
	 * Build current query string (for preserving filter state in pagination links).
	 */
	public function getQueryParams(): array {
		$params = [];
		foreach ($this->fields as $field) {
			$val = $this->values[$field->key] ?? null;
			if ($val === null || $val === '' || $val === []) {
				continue;
			}
			if ($field->type === FilterFieldTypeEnum::DATE_CONDITION && is_array($val)) {
				$op = $val['op'] ?? '';
				$date = $val['date'] ?? '';
				if ($op !== '' && $date !== '') {
					$params[$field->key] = $op . ':' . $date;
				}
			} elseif (is_array($val)) {
				$params[$field->key] = implode(',', $val);
			} else {
				$params[$field->key] = (string)$val;
			}
		}
		return $params;
	}

	public function render(): string {
		$html = '<form class="table-filter" method="GET">';

		foreach ($this->fields as $field) {
			$html .= '<div class="table-filter-field">';
			$html .= '<label class="table-filter-label">' . htmlspecialchars($field->label) . '</label>';
			$html .= $this->renderField($field);
			$html .= '</div>';
		}

		$html .= '<div class="table-filter-buttons">';
		$html .= '<button type="submit" class="table-filter-btn">Filter</button>';
		$html .= '<a href="?' . '" class="table-filter-btn table-filter-reset">Reset</a>';
		$html .= '</div>';

		$html .= '</form>';
		return $html;
	}

	private function renderField(TableFilterField $field): string {
		$currentValue = $this->values[$field->key] ?? null;

		return match ($field->type) {
			FilterFieldTypeEnum::MULTI_SELECT => $this->renderMultiSelect($field, $currentValue),
			FilterFieldTypeEnum::SELECT => $this->renderSelect($field, $currentValue),
			FilterFieldTypeEnum::NUMBER_INPUT => $this->renderNumberInput($field, $currentValue),
			FilterFieldTypeEnum::DATE_CONDITION => $this->renderDateCondition($field, $currentValue),
		};
	}

	private function renderMultiSelect(TableFilterField $field, mixed $selected): string {
		$selectedArr = is_array($selected) ? $selected : [];
		$name = htmlspecialchars($field->key);

		$selectedLabels = [];
		foreach ($field->options as $val => $label) {
			if (in_array((string)$val, $selectedArr, true)) {
				$selectedLabels[] = $label;
			}
		}
		$triggerText = empty($selectedLabels) ? 'All' : implode(', ', $selectedLabels);

		$html = '<div class="multi-select" data-name="' . $name . '">';
		$html .= '<button type="button" class="multi-select-trigger" title="' . htmlspecialchars($triggerText) . '">';
		$html .= htmlspecialchars($triggerText);
		$html .= '</button>';
		$html .= '<div class="multi-select-dropdown">';

		foreach ($field->options as $val => $label) {
			$checked = in_array((string)$val, $selectedArr, true) ? ' checked' : '';
			$valAttr = htmlspecialchars((string)$val);
			$html .= '<label class="multi-select-option">';
			$html .= '<input type="checkbox" value="' . $valAttr . '"' . $checked . '>';
			$html .= ' ' . htmlspecialchars($label);
			$html .= '</label>';
		}

		$html .= '</div>';
		$html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars(implode(',', $selectedArr)) . '">';
		$html .= '</div>';

		return $html;
	}

	private function renderSelect(TableFilterField $field, mixed $selected): string {
		$name = htmlspecialchars($field->key);
		$html = '<select name="' . $name . '" class="table-filter-select">';
		foreach ($field->options as $val => $label) {
			$selAttr = ((string)$val === (string)($selected ?? '')) ? ' selected' : '';
			$html .= '<option value="' . htmlspecialchars((string)$val) . '"' . $selAttr . '>';
			$html .= htmlspecialchars($label) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	private function renderNumberInput(TableFilterField $field, mixed $value): string {
		$name = htmlspecialchars($field->key);
		$val = ($value !== null) ? htmlspecialchars((string)$value) : '';
		$placeholder = $field->placeholder ? ' placeholder="' . htmlspecialchars($field->placeholder) . '"' : '';
		return '<input type="number" name="' . $name . '" value="' . $val . '" class="table-filter-number"' . $placeholder . '>';
	}

	private function renderDateCondition(TableFilterField $field, mixed $value): string {
		$name = htmlspecialchars($field->key);
		$operator = '';
		$date = '';
		if (is_array($value)) {
			$operator = $value['op'] ?? '';
			$date = $value['date'] ?? '';
		}

		$operators = [
			'' => 'Any',
			'before' => 'Before',
			'on' => 'On',
			'after' => 'After',
		];

		$triggerText = 'Any';
		if ($operator !== '' && $date !== '') {
			$triggerText = $operators[$operator] . ' ' . $date;
		}

		$hiddenVal = ($operator !== '' && $date !== '') ? $operator . ':' . $date : '';

		$html = '<div class="date-condition" data-name="' . $name . '">';
		$html .= '<button type="button" class="date-condition-trigger" title="' . htmlspecialchars($triggerText) . '">';
		$html .= htmlspecialchars($triggerText);
		$html .= '</button>';
		$html .= '<div class="date-condition-dropdown">';

		foreach ($operators as $val => $label) {
			$checked = ($val === $operator) ? ' checked' : '';
			$html .= '<label class="date-condition-option">';
			$html .= '<input type="radio" name="' . $name . '_op" value="' . htmlspecialchars($val) . '"' . $checked . '>';
			$html .= ' ' . htmlspecialchars($label);
			$html .= '</label>';
		}

		$html .= '<div class="date-condition-input">';
		$html .= '<input type="date" class="date-condition-date" value="' . htmlspecialchars($date) . '">';
		$html .= '</div>';

		$html .= '</div>';
		$html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($hiddenVal) . '">';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Parse a date condition value from the query string ("operator:YYYY-MM-DD").
	 *
	 * @return array{op: string, date: string}|null Parsed condition or null.
	 */
	private static function parseDateCondition(mixed $raw): ?array {
		if (!is_string($raw) || $raw === '') {
			return null;
		}
		$parts = explode(':', $raw, 2);
		if (count($parts) !== 2) {
			return null;
		}
		$op = $parts[0];
		$date = $parts[1];
		if (!in_array($op, ['before', 'on', 'after'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return null;
		}
		return ['op' => $op, 'date' => $date];
	}
}
