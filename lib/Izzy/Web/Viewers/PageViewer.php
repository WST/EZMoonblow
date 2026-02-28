<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageViewer
{
	protected WebApplication $webApp;

	protected array $menu = [
		['title' => 'Balance', 'url' => '/'],
		['title' => 'Pairs', 'url' => '/pairs.jsp'],
		['title' => 'Positions', 'url' => '/positions.jsp'],
		['title' => 'Candles', 'url' => '/candles.jsp'],
		['title' => 'Backtest', 'url' => '/backtest.jsp'],
		['title' => 'Results', 'url' => '/results.jsp'],
		['title' => 'Optimizations', 'url' => '/optimizations.jsp'],
		['title' => 'System Status', 'url' => '/status.jsp'],
		['title' => 'Log Out', 'url' => '/logout.jsp'],
	];

	public function __construct(WebApplication $webApp) {
		$this->webApp = $webApp;
	}

	public function render(Response $response, ?Request $request = null): Response {
		$body = $this->webApp->getTwig()->render('page.htt', ['menu' => $this->menu]);
		$response->getBody()->write($body);
		return $response;
	}

	// ─── query-building helpers for subclasses ───

	/**
	 * Build raw SQL fragments for a date condition on a Unix timestamp column.
	 *
	 * @param string $column Database column name.
	 * @param mixed $value Parsed date condition array from TableFilter.
	 * @return string[] Raw SQL condition strings.
	 */
	protected static function buildDateConditionSql(string $column, mixed $value): array {
		if (!is_array($value) || empty($value['op']) || empty($value['date'])) {
			return [];
		}

		$date = $value['date'];
		$dayStart = strtotime($date . ' 00:00:00');
		$dayEnd = strtotime($date . ' 23:59:59');
		if ($dayStart === false || $dayEnd === false) {
			return [];
		}

		return match ($value['op']) {
			'before' => ["`$column` > 0 AND `$column` < $dayStart"],
			'on' => ["`$column` >= $dayStart AND `$column` <= $dayEnd"],
			'after' => ["`$column` > $dayEnd"],
			default => [],
		};
	}

	/**
	 * Merge an array-based where clause with additional raw SQL conditions
	 * into a single SQL string.
	 *
	 * @param array $where Key-value where conditions.
	 * @param string[] $rawConditions Additional raw SQL fragments.
	 * @return string Combined WHERE clause as a string.
	 */
	protected static function mergeWhereWithRawConditions(array $where, array $rawConditions): string {
		$parts = [];

		foreach ($where as $field => $value) {
			if (is_array($value)) {
				if (empty($value)) {
					$parts[] = '1 = 0';
				} else {
					$escaped = array_map(fn($v) => "'" . addslashes((string)$v) . "'", $value);
					$parts[] = "`$field` IN (" . implode(', ', $escaped) . ")";
				}
			} else {
				$parts[] = "`$field` = '" . addslashes((string)$value) . "'";
			}
		}

		$parts = array_merge($parts, $rawConditions);

		return empty($parts) ? '1' : implode(' AND ', $parts);
	}
}
