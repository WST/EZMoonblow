<?php

namespace Izzy\Interfaces;

/**
 * Интерфейс криптобиржи
 */
interface IExchangeDriver
{
	/**
	 * Обновить информацию с биржи / на бирже
	 * @return int на сколько секунд заснуть после обновления
	 */
	public function update(): int;

	// Установить соединение с биржей
	public function connect(): bool;

	// Отсоединиться от биржи
	public function disconnect(): void;
}
