<?php

namespace Izzy\Web\Viewers;

use Izzy\Enums\TableViewerColumnTypeEnum;

/**
 * Base class for table display.
 */
class TableViewer
{
	protected array $columns = [];
	protected array $data = [];
	protected string $caption = '';
	protected array $options = [];
	protected array $extraCSSClasses = [];

	public function __construct(array $options = []) {
		$this->options = array_merge([
			'class' => '',
			'striped' => true,
			'hover' => true,
			'bordered' => true,
			'compact' => false
		], $options);
	}

	public function setCaption(string $caption): self {
		$this->caption = $caption;
		return $this;
	}

	public function insertTextColumn(string $key, string $title, array $options = []): self {
		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'left',
			'width' => 'auto',
			'type' => TableViewerColumnTypeEnum::TEXT,
			'class' => ''
		], $options);
		return $this;
	}

	public function insertMoneyColumn(string $key, string $title, array $options = []): self {
		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'right',
			'width' => 'auto',
			'type' => TableViewerColumnTypeEnum::MONEY,
			'class' => ''
		], $options);
		return $this;
	}

	public function insertPercentColumn(string $key, string $title, array $options = []): self {
		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'right',
			'width' => 'auto',
			'type' => TableViewerColumnTypeEnum::PERCENT,
			'class' => ''
		], $options);
		return $this;
	}

	public function insertNumberColumn(string $key, string $title, array $options = []): self {
		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'right',
			'width' => 'auto',
			'type' => TableViewerColumnTypeEnum::NUMBER,
			'class' => ''
		], $options);
		return $this;
	}

	public function insertHtmlColumn(string $key, string $title, array $options = []): self {
		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'left',
			'width' => 'auto',
			'type' => TableViewerColumnTypeEnum::HTML,
			'class' => ''
		], $options);
		return $this;
	}

	public function insertCustomColumn(string $key, string $title, callable $formatter, array $options = []): self {
		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'left',
			'width' => 'auto',
			'type' => TableViewerColumnTypeEnum::CUSTOM,
			'formatter' => $formatter,
			'class' => ''
		], $options);
		return $this;
	}

	public function setData(array $data): self {
		$this->data = $data;
		return $this;
	}

	public function setOptions(array $options): self {
		$this->options = array_merge($this->options, $options);
		return $this;
	}

	public function setExtraCSSClasses(array $classes): self {
		$this->extraCSSClasses = $classes;
		return $this;
	}

	public function render(): string {
		$html = '<table class="' . $this->getTableClass() . '">';

		if (!empty($this->caption)) {
			$html .= '<caption>' . htmlspecialchars($this->caption) . '</caption>';
		}

		$html .= '<thead><tr>';
		foreach ($this->columns as $column) {
			$html .= '<th class="' . $column['class'] . '" style="text-align: ' . $column['align'] . '; width: ' . $column['width'] . ';">';
			$html .= htmlspecialchars($column['title']);
			$html .= '</th>';
		}
		$html .= '</tr></thead>';

		$html .= '<tbody>';
		foreach ($this->data as $index => $row) {
			$rowClass = $this->getRowClass($index);
			$html .= '<tr class="' . $rowClass . '">';

			foreach ($this->columns as $key => $column) {
				$value = $row[$key] ?? '';
				$formattedValue = $this->formatValue($value, $column);

				$html .= '<td class="' . $column['class'] . '" style="text-align: ' . $column['align'] . ';">';
				if ($column['type']->isHtml()) {
					$html .= $formattedValue;
				} else {
					$html .= htmlspecialchars($formattedValue);
				}
				$html .= '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	protected function getTableClass(): string {
		$classes = ['data-table'];

		if (!empty($this->options['class'])) {
			$classes[] = $this->options['class'];
		}

		if (!empty($this->extraCSSClasses)) {
			$classes = array_merge($classes, $this->extraCSSClasses);
		}

		return implode(' ', $classes);
	}

	protected function getRowClass(int $index): string {
		$classes = [];

		if ($this->options['striped'] && $index % 2 === 1) {
			$classes[] = 'even-row';
		}

		return implode(' ', $classes);
	}

	protected function formatValue($value, array $column): string {
		// If value is already formatted (contains currency symbols or %), return as is.
		if (is_string($value) && (strpos($value, 'USDT') !== false || strpos($value, '%') !== false)) {
			return $value;
		}

		/** @var TableViewerColumnTypeEnum $columnType */
		$columnType = $column['type'];

		if ($columnType->isMoney()) {
			return $this->formatMoneyValue($value);
		}

		if ($columnType->isPercent()) {
			return $this->formatPercentValue($value);
		}

		if ($columnType->isNumber()) {
			return $this->formatNumberValue($value);
		}

		if ($columnType->isCustom()) {
			return $this->formatCustomValue($value, $column['formatter']);
		}

		if ($columnType->isHtml()) {
			return $this->formatHtmlValue($value);
		}

		// Default to text.
		return $this->formatTextValue($value);
	}

	protected function formatMoneyValue($value): string {
		if ($value instanceof \Izzy\Financial\Money) {
			return number_format($value->getAmount(), 2) . ' ' . $value->getCurrency();
		}

		if (is_numeric($value)) {
			return number_format($value, 2) . ' USDT';
		}

		return '<span class="error">Invalid money format</span>';
	}

	protected function formatPercentValue($value): string {
		if (is_numeric($value)) {
			return number_format($value, 2) . '%';
		}

		return '<span class="error">Invalid percent format</span>';
	}

	protected function formatNumberValue($value): string {
		if (is_numeric($value)) {
			return number_format($value, 2);
		}

		return '<span class="error">Invalid number format</span>';
	}

	protected function formatCustomValue($value, callable $formatter): string {
		try {
			return $formatter($value);
		} catch (\Exception $e) {
			return '<span class="error">Formatting error</span>';
		}
	}

	protected function formatHtmlValue($value): string {
		if (is_string($value)) {
			return $value;
		}

		return '<span class="error">Invalid HTML format</span>';
	}

	protected function formatTextValue($value): string {
		return (string) $value;
	}
}
