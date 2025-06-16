<?php

namespace Izzy\Chart;

class Image
{
	protected $width;
	protected $height;
	protected $image;
	protected $backgroundColor;
	protected $foregroundColor;
	protected $padding = [
		'top' => 40,    // Увеличенный отступ сверху для заголовка
		'right' => 71,  // Скорректированный отступ справа
		'bottom' => 40, // Увеличенный отступ снизу для меток времени
		'left' => 36    // Скорректированный отступ слева для watermark
	];
	protected $fontPath;
	protected $chartArea; // Добавляем свойство для хранения области графика
	protected $colors = []; // Кэш для цветов

	public function __construct(int $width, int $height) {
		$this->width = $width;
		$this->height = $height;
		$this->image = imagecreatetruecolor($width, $height);
		
		// Устанавливаем белый фон
		$this->backgroundColor = $this->color(255, 255, 255);
		imagefill($this->image, 0, 0, $this->backgroundColor);
		
		// Устанавливаем чёрный цвет по умолчанию
		$this->foregroundColor = $this->color(0, 0, 0);

		// Путь к шрифту
		$this->fontPath = IZZY_ROOT . '/fonts/FiraCode-Regular.ttf';

		// Инициализируем область графика
		$this->updateChartArea();
	}

	public function __destruct() {
		if (is_resource($this->image)) {
			imagedestroy($this->image);
		}
	}

	public function color(int $r, int $g, int $b): int {
		$key = "{$r},{$g},{$b}";
		if (!isset($this->colors[$key])) {
			$this->colors[$key] = imagecolorallocate($this->image, $r, $g, $b);
		}
		return $this->colors[$key];
	}

	public function setForegroundColor(int $r, int $g, int $b): void {
		$this->foregroundColor = $this->color($r, $g, $b);
	}

	public function drawLine(int $x1, int $y1, int $x2, int $y2): void {
		imageline($this->image, $x1, $y1, $x2, $y2, $this->foregroundColor);
	}

	public function drawRectangle(int $x1, int $y1, int $x2, int $y2): void {
		imagerectangle($this->image, $x1, $y1, $x2, $y2, $this->foregroundColor);
	}

	public function fillRectangle(int $x1, int $y1, int $x2, int $y2): void {
		imagefilledrectangle($this->image, $x1, $y1, $x2, $y2, $this->foregroundColor);
	}

	public function drawText(int $x, int $y, string $text): void {
		imagestring($this->image, 3, $x, $y, $text, $this->foregroundColor);
	}

	public function drawTTFText(int $x, int $y, string $text, float $size = 12, float $angle = 0): void {
		imagettftext(
			$this->image,
			$size,
			$angle,
			$x,
			$y,
			$this->foregroundColor,
			$this->fontPath,
			$text
		);
	}

	public function save(string $filename): void {
		imagepng($this->image, $filename);
	}

	public function getWidth(): int {
		return $this->width;
	}

	public function getHeight(): int {
		return $this->height;
	}

	public function getPadding(string $side = null): int|array {
		if ($side === null) {
			return $this->padding;
		}
		return $this->padding[$side] ?? 0;
	}

	protected function updateChartArea(): void {
		$this->chartArea = [
			'x' => $this->padding['left'],
			'y' => $this->padding['top'],
			'width' => $this->width - $this->padding['left'] - $this->padding['right'],
			'height' => $this->height - $this->padding['top'] - $this->padding['bottom']
		];
	}

	public function getChartArea(): array {
		return $this->chartArea;
	}

	public function fillChartArea(int $r, int $g, int $b): void {
		$color = $this->color($r, $g, $b);
		imagefilledrectangle(
			$this->image,
			$this->chartArea['x'],
			$this->chartArea['y'],
			$this->chartArea['x'] + $this->chartArea['width'],
			$this->chartArea['y'] + $this->chartArea['height'],
			$color
		);
	}

	public function drawGrid(int $horizontalLines, int $verticalLines, int $r = 240, int $g = 240, int $b = 240): void {
		$color = $this->color($r, $g, $b);
		
		// Горизонтальные линии
		$step = $this->chartArea['height'] / $horizontalLines;
		for ($i = 0; $i <= $horizontalLines; $i++) {
			$y = $this->chartArea['y'] + $i * $step;
			imageline(
				$this->image,
				$this->chartArea['x'],
				$y,
				$this->chartArea['x'] + $this->chartArea['width'],
				$y,
				$color
			);
		}

		// Вертикальные линии
		$step = $this->chartArea['width'] / $verticalLines;
		for ($i = 0; $i <= $verticalLines; $i++) {
			$x = $this->chartArea['x'] + $i * $step;
			imageline(
				$this->image,
				$x,
				$this->chartArea['y'],
				$x,
				$this->chartArea['y'] + $this->chartArea['height'],
				$color
			);
		}
	}

	public function drawVerticalText(int $x, int $y, string $text, float $size = 12, int $r = 200, int $g = 200, int $b = 200): void {
		$this->setForegroundColor($r, $g, $b);
		
		// Получаем размеры текста при 0 градусов
		$bbox = imagettfbbox($size, 0, $this->fontPath, $text);
		
		// Высота текста после поворота на 90 градусов (т.е. его оригинальная ширина)
		$rotatedTextHeight = abs($bbox[2] - $bbox[0]);
		
		// X-координата для imagettftext (горизонтальная позиция на изображении)
		$imagettftext_x = $x; // Оставляем X как есть, так как это точка отсчета для текста
		
		// Y-координата для imagettftext (вертикальная позиция на изображении)
		// Чтобы центр текста совпал с $y, нижняя точка текста должна быть на $y + (половина его повернутой высоты)
		$imagettftext_y = $y + ($rotatedTextHeight / 2);

		imagettftext(
			$this->image,
			$size,
			90, // Угол поворота (против часовой стрелки)
			$imagettftext_x,
			$imagettftext_y,
			$this->foregroundColor,
			$this->fontPath,
			$text
		);
	}

	public function drawHorizontalText(int $x, int $y, string $text, float $size = 12, int $r = 32, int $g = 32, int $b = 32): void {
		$this->setForegroundColor($r, $g, $b);
		$this->drawTTFText($x, $y, $text, $size);
	}

	public function drawCandle(int $x, int $y, int $width, int $height, bool $isBullish, int $wickTop, int $wickBottom): void {
		// Устанавливаем цвет в зависимости от типа свечи
		if ($isBullish) {
			$this->setForegroundColor(0, 200, 0);
		} else {
			$this->setForegroundColor(200, 0, 0);
		}

		// Рисуем фитиль
		$wickX = $x + $width / 2;
		$this->drawLine($wickX, $wickTop, $wickX, $wickBottom);

		// Рисуем тело свечи
		$this->fillRectangle($x, $y, $x + $width, $y + $height);
	}

	public function setPadding(array $padding): void {
		$this->padding = array_merge($this->padding, $padding);
		$this->updateChartArea();
	}
}
