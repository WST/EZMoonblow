<?php

namespace Izzy\Web\Table;

class ClearAllGlobalAction extends AbstractTableGlobalAction
{
	public function __construct(
		private string $endpoint,
		private string $label = 'Clear All',
		private string $confirmMessage = 'Delete ALL records? This cannot be undone.',
	) {}

	public function render(): string {
		return '<button class="table-global-action table-global-action-danger" '
			. 'data-action="global-action" '
			. 'data-endpoint="' . htmlspecialchars($this->endpoint) . '" '
			. 'data-payload="{}" '
			. 'data-confirm="' . htmlspecialchars($this->confirmMessage) . '">'
			. htmlspecialchars($this->label)
			. '</button>';
	}
}
