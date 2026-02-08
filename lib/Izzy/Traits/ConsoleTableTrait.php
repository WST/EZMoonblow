<?php

namespace Izzy\Traits;

/**
 * Provides methods for rendering Unicode box-drawing tables and formatting durations.
 * Used by Backtest DTO classes and anything that needs to print tabular console output.
 */
trait ConsoleTableTrait
{
	/**
	 * Render a Unicode box-drawing table as a string.
	 *
	 * @param string $title Table title (displayed centered above the table in bold).
	 * @param string[] $headers Column headers, e.g. ['Metric', 'Value'].
	 * @param array<array<string>> $rows Table rows, each an array of cell values.
	 * @return string The rendered table ready for output.
	 */
	protected function renderTable(string $title, array $headers, array $rows): string {
		$colCount = count($headers);

		// Use mb_strwidth for visual width so multi-byte chars (e.g. →) are measured correctly.
		$widths = array_map('mb_strwidth', $headers);
		foreach ($rows as $row) {
			foreach (array_keys($headers) as $i) {
				$cell = isset($row[$i]) ? (string) $row[$i] : '';
				$widths[$i] = max($widths[$i], mb_strwidth($cell));
			}
		}
		$totalWidth = array_sum($widths) + 3 * $colCount;
		$titleLen = mb_strwidth($title);
		$pad = $totalWidth >= $titleLen ? (int) floor(($totalWidth - $titleLen) / 2) : 0;

		$out = PHP_EOL;
		$out .= "\033[1m" . str_repeat(' ', max(0, $pad)) . $title . str_repeat(' ', max(0, $totalWidth - $titleLen - $pad)) . "\033[0m" . PHP_EOL;

		$top = '┌';
		foreach (array_keys($widths) as $idx) {
			$top .= str_repeat('─', $widths[$idx] + 2);
			$top .= ($idx < $colCount - 1) ? '┬' : '┐';
		}
		$out .= $top . PHP_EOL;

		$headerRow = '│';
		foreach (array_keys($headers) as $i) {
			$headerRow .= ' ' . $this->mbStrPad($headers[$i], $widths[$i]) . ' │';
		}
		$out .= $headerRow . PHP_EOL;

		$mid = '├';
		foreach (array_keys($widths) as $idx) {
			$mid .= str_repeat('─', $widths[$idx] + 2);
			$mid .= ($idx < $colCount - 1) ? '┼' : '┤';
		}
		$out .= $mid . PHP_EOL;

		foreach ($rows as $row) {
			$line = '│';
			foreach (array_keys($headers) as $i) {
				$cell = isset($row[$i]) ? (string) $row[$i] : '';
				$line .= ' ' . $this->mbStrPad($cell, $widths[$i]) . ' │';
			}
			$out .= $line . PHP_EOL;
		}

		$bot = '└';
		foreach (array_keys($widths) as $idx) {
			$bot .= str_repeat('─', $widths[$idx] + 2);
			$bot .= ($idx < $colCount - 1) ? '┴' : '┘';
		}
		$out .= $bot . PHP_EOL;

		return $out;
	}

	/**
	 * Multi-byte-safe str_pad: pads to the desired visual width,
	 * accounting for multi-byte characters that occupy fewer display
	 * columns than their byte length (e.g. "→" is 3 bytes but 1 column).
	 */
	private function mbStrPad(string $str, int $width, string $padChar = ' '): string {
		$visualWidth = mb_strwidth($str);
		if ($visualWidth >= $width) {
			return $str;
		}
		return $str . str_repeat($padChar, $width - $visualWidth);
	}

	/**
	 * Format a duration in seconds to a human-readable string (e.g. "5d 12h 30m").
	 */
	protected function formatDuration(int $seconds): string {
		if ($seconds < 0) {
			return '0';
		}
		$d = (int) floor($seconds / 86400);
		$h = (int) floor(($seconds % 86400) / 3600);
		$m = (int) floor(($seconds % 3600) / 60);
		$parts = [];
		if ($d > 0) {
			$parts[] = $d . 'd';
		}
		if ($h > 0 || $d > 0) {
			$parts[] = $h . 'h';
		}
		if ($m > 0 || $parts === []) {
			$parts[] = $m . 'm';
		}
		return implode(' ', $parts);
	}
}
