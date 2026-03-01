<?php

namespace Izzy\Web\Table;

/**
 * Abstract base for table-wide actions rendered in a full-colspan footer row.
 */
abstract class AbstractTableGlobalAction
{
	abstract public function render(): string;
}
