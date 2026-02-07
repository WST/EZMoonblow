<?php

namespace Izzy\Enums;

enum OrderStatusEnum: string
{
	case NewOrder = 'New';
	case PartiallyFilled = 'PartiallyFilled';
	case Filled = 'Filled';

	public function isNew(): bool {
		return $this === self::NewOrder;
	}

	public function isPartiallyFilled(): bool {
		return $this === self::PartiallyFilled;
	}

	public function isFilled(): bool {
		return $this === self::Filled;
	}

	/**
	 * By “Active” we mean new or partially filled.
	 * @return bool
	 */
	public function isActive(): bool {
		return $this->isNew() || $this->isPartiallyFilled();
	}
}
