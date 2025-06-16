<?php

namespace Izzy\Interfaces;

interface IStrategy
{
	/**
	 * Метод должен вернуть true, чтобы обозначить, что стратегия «хочет» встать в лонг
	 * или купить ресурс на споте.
	 * @return bool
	 */ 
	public function shouldLong(): bool;
	
	/**
	 * Метод должен вернуть true, чтобы обозначить, что стратегия «хочет» встать в шорт.
	 * При спотовой торговле данный метод не вызывается.
	 * @return bool 
	 */
	public function shouldShort(): bool;
	
	public function handleLong();
	
	public function handleShort();
	
	public function updatePosition();
}