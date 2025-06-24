<?php

namespace Izzy\Financial;

/**
 * Базовый класс DCA-стратегии, реализующей логику усреднений при просадках
 */
abstract class DCAStrategy extends Strategy
{
	/**
	 * В базовом варианте DCA-стратегии мы будем всегда заходить в лонг.
	 * @return bool
	 */
	public function shouldLong(): bool {
		return true;
	}

	/**
	 * В базовой стратегии не шортим.
	 * @return bool
	 */
	public function shouldShort(): bool {
		return false;
	}
}
