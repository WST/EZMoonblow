<?php

namespace Izzy\Web\Table;

/**
 * Abstract base for row-level actions rendered in the Actions column.
 */
abstract class AbstractTableAction
{
	abstract public function getLabel(): string;

	abstract public function getCssClass(): string;

	abstract public function renderButton(array $row): string;
}
