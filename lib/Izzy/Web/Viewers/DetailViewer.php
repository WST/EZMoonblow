<?php

namespace Izzy\Web\Viewers;

use Izzy\Enums\TableViewerColumnTypeEnum;

/**
 * Class for displaying detailed information as key-value table.
 */
class DetailViewer extends TableViewer
{
	private string $keyColumn = 'key';
	private string $valueColumn = 'value';
	private bool $showHeader = true;

	public function __construct(array $options = []) {
		// Extract showHeader option before passing to parent.
		$this->showHeader = $options['showHeader'] ?? true;
		unset($options['showHeader']);

		// Disable row striping for DetailViewer by default.
		$options = array_merge(['striped' => false], $options);
		parent::__construct($options);

		// Default column configuration for DetailViewer.
		$this->insertKeyColumn($this->keyColumn, 'Parameter', null, [
			'align' => 'left',
			'class' => 'param-name'
		]);

		$this->insertValueColumn($this->valueColumn, 'Value', [
			'align' => 'left',
			'width' => '50%',
			'class' => 'param-value'
		]);
	}

	public function setKeyColumn(string $keyColumn): self {
		$this->keyColumn = $keyColumn;
		return $this;
	}

	public function setValueColumn(string $valueColumn): self {
		$this->valueColumn = $valueColumn;
		return $this;
	}

	public function setShowHeader(bool $showHeader): self {
		$this->showHeader = $showHeader;
		return $this;
	}

	public function insertKeyColumn(string $key, string $title, ?callable $formatter = null, ?array $options = []): self {
		$this->keyColumn = $key;

		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'left',
			'type' => TableViewerColumnTypeEnum::CUSTOM,
			'formatter' => $formatter ?? function ($value) {
					return $value;
				},
			'class' => 'param-name'
		], $options);

		return $this;
	}

	public function insertValueColumn(string $key, string $title, array $options = []): self {
		$this->valueColumn = $key;

		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => 'left',
			'width' => '50%',
			'type' => TableViewerColumnTypeEnum::TEXT,
			'class' => 'param-value'
		], $options);

		return $this;
	}

	public function setDataFromArray(array $data): self {
		$tableData = [];
		foreach ($data as $key => $value) {
			$tableData[] = [
				$this->keyColumn => $key,
				$this->valueColumn => $value
			];
		}

		$this->setData($tableData);
		return $this;
	}

	public function render(): string {
		// Add DetailViewer-specific CSS class.
		$this->setExtraCSSClasses(['detail-viewer-table']);

		if (!$this->showHeader) {
			// Render without header.
			return $this->renderWithoutHeader();
		}

		return parent::render();
	}

	private function renderWithoutHeader(): string {
		$html = '<table class="' . $this->getTableClass() . '">';

		if (!empty($this->caption)) {
			$html .= '<caption>' . htmlspecialchars($this->caption) . '</caption>';
		}

		$html .= '<tbody>';
		foreach ($this->data as $row) {
			$html .= '<tr>';

			foreach ($this->columns as $key => $column) {
				$value = $row[$key] ?? '';
				$formattedValue = $this->formatValue($value, $column, $row);

				$html .= '<td class="' . $column['class'] . '" style="text-align:' . $column['align'] . ';">';
				if ($column['type']->rendersHtml()) {
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
}
