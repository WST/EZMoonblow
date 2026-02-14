<?php

namespace Izzy\Enums;

enum OrderStatusEnum: string
{
	/** Order has been placed successfully. */
	case NewOrder = 'New';

	/** Order is partially filled. */
	case PartiallyFilled = 'PartiallyFilled';

	/** Order is fully filled. */
	case Filled = 'Filled';

	/** Order was cancelled (may have partial fills in derivatives). */
	case Cancelled = 'Cancelled';

	/** Order was rejected by the exchange. */
	case Rejected = 'Rejected';

	/** Conditional order waiting for trigger condition. */
	case Untriggered = 'Untriggered';

	/** Conditional order has been triggered (transitional state). */
	case Triggered = 'Triggered';

	/** TP/SL or conditional order deactivated before triggering. */
	case Deactivated = 'Deactivated';

	/** Spot only: partially filled then cancelled. */
	case PartiallyFilledCanceled = 'PartiallyFilledCanceled';

	public function isNew(): bool {
		return $this === self::NewOrder;
	}

	public function isPartiallyFilled(): bool {
		return $this === self::PartiallyFilled;
	}

	public function isFilled(): bool {
		return $this === self::Filled;
	}

	public function isCancelled(): bool {
		return $this === self::Cancelled || $this === self::PartiallyFilledCanceled;
	}

	public function isRejected(): bool {
		return $this === self::Rejected;
	}

	public function isDeactivated(): bool {
		return $this === self::Deactivated;
	}

	/**
	 * By "Active" we mean new, partially filled, or waiting for trigger.
	 */
	public function isActive(): bool {
		return $this === self::NewOrder
			|| $this === self::PartiallyFilled
			|| $this === self::Untriggered;
	}

	/**
	 * Terminal states: the order will not change anymore.
	 */
	public function isTerminal(): bool {
		return $this === self::Filled
			|| $this === self::Cancelled
			|| $this === self::Rejected
			|| $this === self::Deactivated
			|| $this === self::PartiallyFilledCanceled;
	}
}
