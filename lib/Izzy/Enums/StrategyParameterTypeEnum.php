<?php

namespace Izzy\Enums;

/**
 * Data type of a strategy configuration parameter.
 * Used by the web UI to render the appropriate form control.
 */
enum StrategyParameterTypeEnum: string
{
	case STRING = 'string';
	case INT = 'int';
	case FLOAT = 'float';
	case BOOL = 'bool';
	case SELECT = 'select';

	public function isString(): bool {
		return $this === self::STRING;
	}

	public function isInteger(): bool {
		return $this === self::INT;
	}

	public function isFloat(): bool {
		return $this === self::FLOAT;
	}

	public function isBoolean(): bool {
		return $this === self::BOOL;
	}

	public function isSelect(): bool {
		return $this === self::SELECT;
	}

	public function isNumeric(): bool {
		return $this === self::INT || $this === self::FLOAT;
	}
}
