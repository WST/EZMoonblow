<?php

namespace Izzy\Web\Table;

/**
 * Delete action for rows identified by a composite key (multiple fields).
 * The payload is sent as a JSON object containing all mapped fields.
 */
class CompositeDeleteAction extends AbstractTableAction
{
	/**
	 * @param string $endpoint API endpoint URL.
	 * @param array<string, string> $payloadFields Map of payload JSON keys to row array keys.
	 * @param string $confirmMessage Confirmation dialog message.
	 */
	public function __construct(
		private string $endpoint,
		private array $payloadFields,
		private string $confirmMessage = 'Are you sure you want to delete this record?',
	) {}

	public function getLabel(): string {
		return 'Delete';
	}

	public function getCssClass(): string {
		return 'table-action-btn table-action-delete';
	}

	public function renderButton(array $row): string {
		$payload = [];
		foreach ($this->payloadFields as $payloadKey => $rowKey) {
			$payload[$payloadKey] = $row[$rowKey] ?? '';
		}

		return '<button class="' . $this->getCssClass() . '" '
			. 'data-action="delete" '
			. 'data-endpoint="' . htmlspecialchars($this->endpoint) . '" '
			. 'data-payload="' . htmlspecialchars(json_encode($payload)) . '" '
			. 'data-confirm="' . htmlspecialchars($this->confirmMessage) . '" '
			. 'title="Delete">&times;</button>';
	}
}
