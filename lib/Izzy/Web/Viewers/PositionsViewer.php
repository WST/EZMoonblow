<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\AbstractWebApplication;
use Izzy\Financial\StoredPosition;
use Izzy\Web\Filters\PositionsFilter;
use Izzy\Web\Table\DeleteAction;
use Izzy\Web\Table\TableFilter;
use Izzy\Web\Table\TablePagination;
use Izzy\Web\Tables\PositionsTable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Viewer for the Positions page.
 * Displays trading positions with filtering and pagination via TableViewer.
 */
class PositionsViewer extends PageViewer
{
	public function __construct(AbstractWebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
		$database = $this->webApp->getDatabase();

		$filterTemplate = PositionsFilter::create($database);

		if ($request !== null) {
			$filter = TableFilter::fromRequest($request, $filterTemplate);
			$paginationRequest = TablePagination::fromRequest($request, 50);
		} else {
			$filter = $filterTemplate;
			$paginationRequest = new TablePagination(1, 50);
		}

		[$where, $orderBy] = $this->buildQuery($filter);

		$total = StoredPosition::countFiltered($database, $where);
		$pagination = $paginationRequest->withTotal($total);
		$records = StoredPosition::loadFiltered(
			$database, $where, $orderBy,
			$pagination->getPerPage(), $pagination->getOffset()
		);

		$positions = array_map(fn(StoredPosition $p) => $p->toArray(), $records);

		$table = PositionsTable::create();
		$table->setPagination($pagination);
		$table->addAction(new DeleteAction('/cgi-bin/api.pl?action=delete_position', 'positionId'));
		$table->setData($positions);

		$baseUrl = '/positions.jsp?' . http_build_query($filter->getQueryParams());

		$body = $this->webApp->getTwig()->render('positions.htt', [
			'menu' => $this->menu,
			'filterHtml' => $filter->render(),
			'tableHtml' => $table->render(),
			'paginationHtml' => $pagination->render($baseUrl),
			'positions' => $positions,
		]);

		$response->getBody()->write($body);
		return $response;
	}

	/**
	 * Translate filter values into a WHERE clause and ORDER BY string.
	 *
	 * @return array{0: string|array, 1: string}
	 */
	private function buildQuery(TableFilter $filter): array {
		$where = [];

		$direction = $filter->getValue('direction');
		if (!empty($direction)) {
			$where[StoredPosition::FDirection] = $direction;
		}

		$marketType = $filter->getValue('marketType');
		if (!empty($marketType)) {
			$where[StoredPosition::FMarketType] = $marketType;
		}

		$exchange = $filter->getValue('exchange');
		if (!empty($exchange)) {
			$where[StoredPosition::FExchangeName] = $exchange;
		}

		$ticker = $filter->getValue('ticker');
		if (!empty($ticker)) {
			$where[StoredPosition::FTicker] = $ticker;
		}

		$status = $filter->getValue('status');
		if (!empty($status)) {
			$where[StoredPosition::FStatus] = $status;
		}

		$dateConditions = array_merge(
			static::buildDateConditionSql(StoredPosition::FCreatedAt, $filter->getValue('created')),
			static::buildDateConditionSql(StoredPosition::FFinishedAt, $filter->getValue('finished'))
		);

		if (!empty($dateConditions)) {
			$where = static::mergeWhereWithRawConditions($where, $dateConditions);
		}

		$orderBy = StoredPosition::getDefaultSortOrder();

		return [$where, $orderBy];
	}
}
