<?php

namespace Izzy\Chart;

use Izzy\Market;

class Chart extends Image
{
	protected $market;
	protected $candleWidth = 4;
	protected $candleSpacing = 2;  // Изменено с 1 на 2
	protected $bullishColor;
	protected $bearishColor;
	protected $wickColor;
	protected $priceColor;  // Цвет для цен
	protected $backgroundColor;      // Цвет фона всего изображения
	protected $chartBackgroundColor; // Цвет фона области с графиком
	protected $timeframe;  // Добавляем свойство для таймфрейма

	public function __construct(Market $market, string $timeframe, int $height = 480) {
		// Рассчитываем ширину на основе количества свечей
		$candleCount = count($market->getCandles());
		$width = $candleCount * ($this->candleWidth + $this->candleSpacing) + 110;  // 110 = left padding (30) + right padding (80)
		
		parent::__construct($width, $height);
		
		$this->market = $market;
		$this->timeframe = $timeframe;

		// Устанавливаем цвета
		$this->backgroundColor = $this->color(240, 240, 240);      // Серый фон для всего изображения
		$this->chartBackgroundColor = $this->color(255, 255, 255); // Белый фон для области графика
	}

	public function draw(): void {
		$this->drawChartBackground();
		$this->drawChartGrid();
		$this->drawPriceScale();  // Сначала рисуем сетку и шкалу цен
		$this->drawCandles();     // Потом рисуем свечи поверх
		$this->drawTimeScale();
		$this->drawTitle();
		$this->drawWatermark();
	}

	public function drawChartGrid(): void {
		$this->drawGrid(10, 10);
	}

	protected function formatPrice(float $price): string {
		// Если цена меньше 0.01, показываем 5 знаков после запятой
		if ($price < 0.01) {
			return number_format($price, 5);
		}
		// Если цена меньше 1, показываем 4 знака
		if ($price < 1) {
			return number_format($price, 4);
		}
		// Если цена меньше 10, показываем 3 знака
		if ($price < 10) {
			return number_format($price, 3);
		}
		// Если цена меньше 100, показываем 2 знака
		if ($price < 100) {
			return number_format($price, 2);
		}
		// Для больших цен показываем 1 знак
		return number_format($price, 1);
	}

	protected function drawCandles(): void {
		$chartArea = $this->getChartArea();
		$candles = $this->market->getCandles();
		$candleCount = count($candles);
		
		if ($candleCount === 0) {
			return;
		}

		$priceRange = $this->market->getPriceRange();
		$priceScale = $chartArea['height'] / $priceRange;

		foreach ($candles as $i => $candle) {
			// Рассчитываем позицию с учётом отступа между свечами
			$x = $chartArea['x'] + $i * ($this->candleWidth + $this->candleSpacing);
			
			// Рассчитываем координаты свечи
			$highY = $chartArea['y'] + $chartArea['height'] - ($candle->getHighPrice() - $this->market->getMinPrice()) * $priceScale;
			$lowY = $chartArea['y'] + $chartArea['height'] - ($candle->getLowPrice() - $this->market->getMinPrice()) * $priceScale;
			$openY = $chartArea['y'] + $chartArea['height'] - ($candle->getOpenPrice() - $this->market->getMinPrice()) * $priceScale;
			$closeY = $chartArea['y'] + $chartArea['height'] - ($candle->getClosePrice() - $this->market->getMinPrice()) * $priceScale;
			
			// Рисуем свечу
			$this->drawCandle(
				$x,
				min($openY, $closeY),
				$this->candleWidth,
				abs($closeY - $openY),
				$candle->isBullish(),
				$highY,
				$lowY
			);
		}
	}

	protected function drawTimeScale(): void {
		$chartArea = $this->getChartArea();
		$candles = $this->market->getCandles();
		if (empty($candles)) {
			return;
		}

		// Определяем интервал между метками времени
		$candleCount = count($candles);
		$interval = max(1, floor($candleCount / 10));

		// Текущая дата для сравнения
		$currentDate = date('Y-m-d');

		for ($i = 0; $i < $candleCount; $i += $interval) {
			$candle = $candles[$i];
			$timestamp = $candle->getOpenTime();
			$date = date('Y-m-d', $timestamp);
			$time = date('H:i', $timestamp);
			
			// Рассчитываем позицию метки
			$x = $chartArea['x'] + $i * ($this->candleWidth + $this->candleSpacing);
			$y = $this->getHeight() - $this->getPadding('bottom') + 15;

			// Если дата не текущая, показываем её на второй строке
			if ($date !== $currentDate) {
				$this->drawHorizontalText($x, $y, $time, 8);
				$this->drawHorizontalText($x, $y + 15, $date, 8);
			} else {
				$this->drawHorizontalText($x, $y, $time, 8);
			}
		}
	}

	protected function drawTitle(): void {
		$title = sprintf("%s %s %s", $this->market->getSymbol(), $this->timeframe, date('Y-m-d H:i:s'));
		$this->drawHorizontalText($this->getPadding('left'), 25, $title, 14);
	}

	protected function drawWatermark(): void {
		$this->drawVerticalText(24, $this->getHeight() / 2, "EZMoonblow v2", 10);
	}

	protected function drawChartBackground(): void {
		// Фон для всего изображения
		$this->setForegroundColor(240, 240, 240);
		$this->fillRectangle(0, 0, $this->getWidth(), $this->getHeight());
		
		// Фон для области графика
		$this->fillChartArea(255, 255, 255);
	}

	protected function drawPriceScale(): void {
		$chartArea = $this->getChartArea();
		$priceRange = $this->market->getPriceRange();
		$step = $priceRange / 10;
		
		for ($i = 0; $i <= 10; $i++) {
			$price = $this->market->getMinPrice() + $i * $step;
			$y = $chartArea['y'] + $chartArea['height'] - ($i * $chartArea['height'] / 10);
			
			// Рисуем линию сетки более светлым цветом
			$this->setForegroundColor(230, 230, 230);
			$this->drawLine(
				$chartArea['x'],
				$y,
				$chartArea['x'] + $chartArea['width'],
				$y
			);
			
			// Рисуем цену
			$this->drawHorizontalText(
				$chartArea['x'] + $chartArea['width'] + 5,
				$y + 5,
				$this->formatPrice($price),
				8
			);
		}
	}
}
