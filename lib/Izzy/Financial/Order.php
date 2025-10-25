<?php

namespace Izzy\Financial;

use Izzy\Enums\OrderStatusEnum;
use Izzy\Enums\OrderTypeEnum;

class Order {
	/**
	 * Order status.
	 * @var OrderStatusEnum
	 */
	protected OrderStatusEnum $status;

	/**
	 * Order type (limit or market).
	 * @var OrderTypeEnum
	 */
	protected OrderTypeEnum $type;

	/**
	 * Id on Exchange.
	 * @var string
	 */
	protected string $idOnExchange;

	/**
	 * Amount in base currency.
	 * @var Money
	 */
	protected Money $volume;

	public function setStatus(OrderStatusEnum $orderStatus): void {
		$this->status = $orderStatus;
	}

	public function setIdOnExchange(mixed $idOnExchange): void {
		$this->idOnExchange = $idOnExchange;
	}

	public function setOrderType(OrderTypeEnum $type): void {
		$this->type = $type;
	}

	public function setVolume(Money $volume): void {
		$this->volume = $volume;
	}

	public function getVolume(): Money {
		return $this->volume;
	}

	public function __construct() {

	}

	public function isActive(): bool {
		return $this->status->isActive();
	}
}
