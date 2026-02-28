<?php

namespace Izzy\Web\Viewers;

use Izzy\Enums\TableViewerColumnTypeEnum;
use Izzy\Financial\Money;
use Izzy\Web\Table\TableAction;
use Izzy\Web\Table\TablePagination;

class TableViewer
{
	protected array $columns = [];
	protected array $data = [];
	protected string $caption = '';
	protected array $options = [];
	protected array $extraCSSClasses = [];
	protected ?TablePagination $pagination = null;
	/** @var TableAction[] */
	protected array $actions = [];
	/** @var ?callable */
	protected $rowClassCallback = null;
	/** @var ?callable */
	protected $rowDataAttributesCallback = null;

	public function __construct(array $options = []) {
		$this->options = array_merge([
			'class' => '',
			'striped' => true,
			'hover' => true,
			'bordered' => true,
			'compact' => false,
		], $options);
	}

	// ─── column insertion methods ───

	public function insertTextColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::TEXT, 'left', $options);
	}

	public function insertMoneyColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::MONEY, 'right', $options);
	}

	public function insertPercentColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::PERCENT, 'right', $options);
	}

	public function insertNumberColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::NUMBER, 'right', $options);
	}

	public function insertIntegerColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::INTEGER, 'right', $options);
	}

	public function insertDateColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::DATE, 'left', $options);
	}

	public function insertBadgeColumn(string $key, string $title, callable $formatter, array $options = []): self {
		$options['formatter'] = $formatter;
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::BADGE, 'center', $options);
	}

	public function insertMarketTypeColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::MARKET_TYPE, 'center', $options);
	}

	/**
	 * Insert a direction column aware of PositionDirectionEnum values.
	 */
	public function insertDirectionColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::DIRECTION, 'center', $options);
	}

	/**
	 * Insert a pair/ticker column with bold display.
	 */
	public function insertPairColumn(string $key, string $title, array $options = []): self {
		$options['bold'] = true;
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::PAIR, 'left', $options);
	}

	/**
	 * Insert a position status column aware of PositionStatusEnum values.
	 * Option 'breakevenLockedKey' specifies the row key for the BL flag.
	 */
	public function insertPositionStatusColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::POSITION_STATUS, 'center', $options);
	}

	public function insertPnlColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::PNL, 'right', $options);
	}

	public function insertHtmlColumn(string $key, string $title, array $options = []): self {
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::HTML, 'left', $options);
	}

	public function insertCustomColumn(string $key, string $title, callable $formatter, array $options = []): self {
		$options['formatter'] = $formatter;
		return $this->insertColumn($key, $title, TableViewerColumnTypeEnum::CUSTOM, 'left', $options);
	}

	private function insertColumn(string $key, string $title, TableViewerColumnTypeEnum $type, string $defaultAlign, array $options = []): self {
		$this->columns[$key] = array_merge([
			'title' => $title,
			'align' => $defaultAlign,
			'headerAlign' => null,
			'width' => 'auto',
			'type' => $type,
			'class' => '',
		], $options);
		return $this;
	}

	// ─── data & config ───

	public function setData(array $data): self {
		$this->data = $data;
		return $this;
	}

	public function setCaption(string $caption): self {
		$this->caption = $caption;
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

	public function setPagination(TablePagination $pagination): self {
		$this->pagination = $pagination;
		return $this;
	}

	public function addAction(TableAction $action): self {
		$this->actions[] = $action;
		return $this;
	}

	public function setRowClassCallback(callable $callback): self {
		$this->rowClassCallback = $callback;
		return $this;
	}

	public function setRowDataAttributes(callable $callback): self {
		$this->rowDataAttributesCallback = $callback;
		return $this;
	}

	// ─── rendering ───

	public function render(): string {
		$html = '<table class="' . $this->getTableClass() . '">';

		if (!empty($this->caption)) {
			$html .= '<caption>' . htmlspecialchars($this->caption) . '</caption>';
		}

		$html .= $this->renderHead();
		$html .= $this->renderBody();
		$html .= '</table>';

		return $html;
	}

	public function renderPagination(string $baseUrl): string {
		if ($this->pagination === null) {
			return '';
		}
		return $this->pagination->render($baseUrl);
	}

	private function renderHead(): string {
		$html = '<thead><tr>';
		foreach ($this->columns as $column) {
			$thAlign = $column['headerAlign'] ?? $column['align'];
			$style = 'text-align:' . $thAlign . ';';
			if ($column['width'] !== 'auto') {
				$style .= 'width:' . $column['width'] . ';';
			}
			$html .= '<th class="' . $column['class'] . '" style="' . $style . '">';
			$html .= htmlspecialchars($column['title']);
			$html .= '</th>';
		}
		if (!empty($this->actions)) {
			$html .= '<th style="width:1%;white-space:nowrap;text-align:center;"></th>';
		}
		$html .= '</tr></thead>';
		return $html;
	}

	private function renderBody(): string {
		$html = '<tbody>';
		foreach ($this->data as $index => $row) {
			$rowClasses = [];
			if ($this->options['striped'] && $index % 2 === 1) {
				$rowClasses[] = 'even-row';
			}
			if ($this->rowClassCallback !== null) {
				$extra = ($this->rowClassCallback)($row, $index);
				if ($extra !== '') {
					$rowClasses[] = $extra;
				}
			}
			$attrs = '';
			if ($this->rowDataAttributesCallback !== null) {
				$dataAttrs = ($this->rowDataAttributesCallback)($row, $index);
				foreach ($dataAttrs as $name => $val) {
					$attrs .= ' ' . htmlspecialchars($name) . '="' . htmlspecialchars((string)$val) . '"';
				}
			}
			$html .= '<tr class="' . implode(' ', $rowClasses) . '"' . $attrs . '>';

			foreach ($this->columns as $key => $column) {
				$value = $row[$key] ?? null;
				$formatted = $this->formatValue($value, $column, $row);
				$tdClass = $column['class'];

				/** @var TableViewerColumnTypeEnum $type */
				$type = $column['type'];

				$html .= '<td class="' . $tdClass . '" style="text-align:' . $column['align'] . ';">';
				if ($type->rendersHtml()) {
					$html .= $formatted;
				} else {
					$escaped = htmlspecialchars($formatted);
					$html .= !empty($column['bold']) ? ('<b>' . $escaped . '</b>') : $escaped;
				}
				$html .= '</td>';
			}

			if (!empty($this->actions)) {
				$html .= '<td class="table-actions-cell" style="text-align:center;white-space:nowrap;">';
				foreach ($this->actions as $action) {
					$html .= $action->renderButton($row);
				}
				$html .= '</td>';
			}

			$html .= '</tr>';
		}
		$html .= '</tbody>';
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

	// ─── formatting ───

	protected function formatValue(mixed $value, array $column, array $row = []): string {
		/** @var TableViewerColumnTypeEnum $type */
		$type = $column['type'];

		return match (true) {
			$type->isCustom() => $this->formatCustomValue($value, $column['formatter'], $row),
			$type->isBadge() => $this->formatBadgeValue($value, $column['formatter'], $row),
			$type->isMoney() => $this->formatMoneyValue($value),
			$type->isPercent() => $this->formatPercentValue($value, $column),
			$type->isNumber() => $this->formatNumberValue($value, $column),
			$type->isInteger() => $this->formatIntegerValue($value),
			$type->isDate() => $this->formatDateValue($value, $column),
			$type->isMarketType() => $this->formatMarketTypeValue($value),
			$type->isDirection() => $this->formatDirectionValue($value),
			$type->isPair() => $this->formatPairValue($value),
			$type->isPositionStatus() => $this->formatPositionStatusValue($value, $column, $row),
			$type->isPnl() => $this->formatPnlValue($value, $column, $row),
			$type->isHtml() => $this->formatHtmlValue($value),
			default => $this->formatTextValue($value),
		};
	}

	protected function formatMoneyValue(mixed $value): string {
		if ($value === null) {
			return '—';
		}
		if ($value instanceof Money) {
			return number_format($value->getAmount(), 2) . ' ' . $value->getCurrency();
		}
		if (is_numeric($value)) {
			return number_format((float)$value, 2) . ' USDT';
		}
		return '—';
	}

	protected function formatPercentValue(mixed $value, array $column): string {
		if (!is_numeric($value)) {
			return '';
		}
		$decimals = $column['decimals'] ?? 2;
		return number_format((float)$value, $decimals) . '%';
	}

	protected function formatNumberValue(mixed $value, array $column): string {
		if (!is_numeric($value)) {
			return '';
		}
		$decimals = $column['decimals'] ?? 2;
		return number_format((float)$value, $decimals);
	}

	protected function formatIntegerValue(mixed $value): string {
		if ($value === null || $value === '') {
			return '';
		}
		return (string)(int)$value;
	}

	protected function formatDateValue(mixed $value, array $column): string {
		if (empty($value)) {
			return '';
		}
		$format = $column['dateFormat'] ?? 'Y-m-d H:i';
		if (is_numeric($value)) {
			return date($format, (int)$value);
		}
		if (is_string($value)) {
			$ts = strtotime($value);
			return $ts !== false ? date($format, $ts) : $value;
		}
		return (string)$value;
	}

	protected function formatBadgeValue(mixed $value, callable $formatter, array $row): string {
		try {
			$badge = $formatter($value, $row);
			$label = htmlspecialchars($badge['label'] ?? '');
			$variant = htmlspecialchars($badge['variant'] ?? 'default');
			return '<span class="badge badge-' . $variant . '">' . $label . '</span>';
		} catch (\Throwable) {
			return '';
		}
	}

	protected function formatMarketTypeValue(mixed $value): string {
		$v = strtolower((string)$value);
		$label = ucfirst($v);
		$variant = match ($v) {
			'futures' => 'warning',
			'spot' => 'info',
			default => 'default',
		};
		return '<span class="badge badge-' . $variant . '">' . htmlspecialchars($label) . '</span>';
	}

	protected function formatPnlValue(mixed $value, array $column, array $row): string {
		if ($value instanceof Money) {
			$value = $value->getAmount();
		}
		if (!is_numeric($value)) {
			return '—';
		}
		$num = (float)$value;
		$formatted = number_format(abs($num), 2);

		$percentKey = $column['percentKey'] ?? null;
		$pctSuffix = '';
		if ($percentKey !== null && isset($row[$percentKey]) && is_numeric($row[$percentKey])) {
			$pct = (float)$row[$percentKey];
			$pctSuffix = ' (' . ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%)';
		}

		if ($num > 0) {
			return '<span class="pnl-positive">+' . $formatted . $pctSuffix . '</span>';
		}
		if ($num < 0) {
			return '<span class="pnl-negative">-' . $formatted . $pctSuffix . '</span>';
		}
		return '<span class="pnl-zero">' . $formatted . $pctSuffix . '</span>';
	}

	protected function formatDirectionValue(mixed $value): string {
		$v = strtoupper((string)$value);
		return match ($v) {
			'LONG' => '<span class="badge badge-success">L</span>',
			'SHORT' => '<span class="badge badge-danger">S</span>',
			default => '<span class="badge badge-default">' . htmlspecialchars($v) . '</span>',
		};
	}

	protected function formatPairValue(mixed $value): string {
		return (string)$value;
	}

	protected function formatPositionStatusValue(mixed $value, array $column, array $row): string {
		$v = strtoupper((string)$value);
		$blKey = $column['breakevenLockedKey'] ?? null;
		$isBreakevenLocked = $blKey !== null && !empty($row[$blKey]);

		if ($v === 'OPEN' && $isBreakevenLocked) {
			return '<span class="badge badge-info">Locked</span>';
		}

		[$label, $variant] = match ($v) {
			'OPEN' => ['Open', 'success'],
			'PENDING' => ['Pending', 'warning'],
			'FINISHED' => ['Finished', 'info'],
			'ERROR' => ['Error', 'danger'],
			'CANCELED' => ['Cancelled', 'secondary'],
			default => [$value, 'default'],
		};
		return '<span class="badge badge-' . $variant . '">' . htmlspecialchars($label) . '</span>';
	}

	protected function formatCustomValue(mixed $value, callable $formatter, array $row): string {
		try {
			return $formatter($value, $row);
		} catch (\Throwable) {
			return '';
		}
	}

	protected function formatHtmlValue(mixed $value): string {
		return is_string($value) ? $value : '';
	}

	protected function formatTextValue(mixed $value): string {
		return (string)$value;
	}
}
