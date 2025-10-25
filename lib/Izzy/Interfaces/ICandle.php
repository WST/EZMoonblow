<?php

namespace Izzy\Interfaces;

/**
 * Интерфейс свечи
 */
interface ICandle {
	// Получить предыдущую свечу (или null для первой)
	public function previousCandle(): ?ICandle;

	// Получить следующую свечу (или null для последней)
	public function nextCandle(): ?ICandle;

	// Получить время открытия свечи
	public function getOpenTime(): int;

	// Получить время закрытия свечи
	public function getCloseTime(): int;

	// Получить цену открытия свечи
	public function getOpenPrice(): float;

	// Получить цену закрытия свечи
	public function getClosePrice(): float;

	// Получить высокую цену свечи
	public function getHighPrice(): float;

	// Получить низкую цену свечи
	public function getLowPrice(): float;

	// Получить объем торгов свечи
	public function getVolume(): float;

	// Получить размер свечи (разницу между ценами открытия и закрытия)
	public function getSize(): float;

	// Получить объём открытого интереса на фьючерсном рынке на момент начала свечи
	public function getOpenInterest(): float;

	// Получить изменение объёма открытого интереса на фьючерсном рынке за время данной свечи
	public function getOpenInterestChange(): float;

	// Получить связанный со свечой имбаланс (если он есть)
	public function getFVG(): ?IFVG;

	// Получить рынок, из которого эта свеча
	public function getMarket(): IMarket;

	// Установить рынок, из которого эта свеча
	public function setMarket(IMarket $market): void;

	// Показывает, что свеча является «бычьей»
	public function isBullish(): bool;

	// Показывает, что свеча является «медвежьей»
	public function isBearish(): bool;
}
