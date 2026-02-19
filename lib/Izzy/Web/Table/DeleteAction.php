<?php

namespace Izzy\Web\Table;

class DeleteAction extends TableAction
{
	public function __construct(
		private string $endpoint,
		private string $idField = 'id',
		private string $confirmMessage = 'Are you sure you want to delete this record?',
	) {}

	public function getLabel(): string {
		return 'Delete';
	}

	public function getCssClass(): string {
		return 'table-action-btn table-action-delete';
	}

	public function renderButton(array $row): string {
		$id = htmlspecialchars((string)($row[$this->idField] ?? ''));
		$endpoint = htmlspecialchars($this->endpoint);
		$confirm = htmlspecialchars($this->confirmMessage);
		return '<button class="' . $this->getCssClass() . '" '
			. 'data-action="delete" '
			. 'data-endpoint="' . $endpoint . '" '
			. 'data-id="' . $id . '" '
			. 'data-confirm="' . $confirm . '" '
			. 'title="Delete">&times;</button>';
	}
}
