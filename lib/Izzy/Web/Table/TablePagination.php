<?php

namespace Izzy\Web\Table;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Server-side pagination for TableViewer.
 * Renders a page-navigation bar and computes SQL LIMIT/OFFSET.
 */
class TablePagination
{
	public function __construct(
		private int $currentPage,
		private int $perPage,
		private int $totalItems = 0,
	) {
		$this->currentPage = max(1, $this->currentPage);
	}

	public static function fromRequest(Request $request, int $perPage = 25): self {
		$page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
		return new self($page, $perPage, 0);
	}

	public function withTotal(int $total): self {
		$clone = clone $this;
		$clone->totalItems = $total;
		if ($clone->currentPage > $clone->getTotalPages()) {
			$clone->currentPage = $clone->getTotalPages();
		}
		return $clone;
	}

	public function getCurrentPage(): int {
		return $this->currentPage;
	}

	public function getPerPage(): int {
		return $this->perPage;
	}

	public function getTotalItems(): int {
		return $this->totalItems;
	}

	public function getOffset(): int {
		return ($this->currentPage - 1) * $this->perPage;
	}

	public function getTotalPages(): int {
		return max(1, (int)ceil($this->totalItems / $this->perPage));
	}

	/**
	 * Render the pagination navigation bar.
	 * Preserves all current query parameters, replacing only "page".
	 */
	public function render(string $baseUrl): string {
		$totalPages = $this->getTotalPages();
		if ($totalPages <= 1) {
			return '';
		}

		$parsed = parse_url($baseUrl);
		$path = $parsed['path'] ?? '/';
		parse_str($parsed['query'] ?? '', $params);

		$link = function (int $page, string $label, bool $disabled = false, bool $active = false) use ($path, $params): string {
			$params['page'] = $page;
			$href = $path . '?' . http_build_query($params);
			$classes = ['table-pagination-link'];
			if ($active) {
				$classes[] = 'active';
			}
			if ($disabled) {
				return '<span class="' . implode(' ', $classes) . ' disabled">' . $label . '</span>';
			}
			return '<a class="' . implode(' ', $classes) . '" href="' . htmlspecialchars($href) . '">' . $label . '</a>';
		};

		$html = '<div class="table-pagination">';

		$html .= $link($this->currentPage - 1, '&laquo; Prev', $this->currentPage <= 1);

		$window = 2;
		$start = max(1, $this->currentPage - $window);
		$end = min($totalPages, $this->currentPage + $window);

		if ($start > 1) {
			$html .= $link(1, '1');
			if ($start > 2) {
				$html .= '<span class="table-pagination-ellipsis">&hellip;</span>';
			}
		}

		for ($i = $start; $i <= $end; $i++) {
			$html .= $link($i, (string)$i, false, $i === $this->currentPage);
		}

		if ($end < $totalPages) {
			if ($end < $totalPages - 1) {
				$html .= '<span class="table-pagination-ellipsis">&hellip;</span>';
			}
			$html .= $link($totalPages, (string)$totalPages);
		}

		$html .= $link($this->currentPage + 1, 'Next &raquo;', $this->currentPage >= $totalPages);

		$html .= '<span class="table-pagination-info">' . $this->totalItems . ' results</span>';
		$html .= '</div>';

		return $html;
	}
}
