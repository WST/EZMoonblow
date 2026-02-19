<?php

namespace Izzy\Web\Table;

use Izzy\Enums\FilterFieldTypeEnum;

class TableFilterField
{
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly FilterFieldTypeEnum $type,
		public readonly array $options = [],
		public readonly ?string $placeholder = null,
	) {}
}
