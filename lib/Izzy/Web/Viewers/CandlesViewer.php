<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Financial\CandleRepository;
use Izzy\Web\Filters\CandlesFilter;
use Izzy\Web\Table\ClearAllGlobalAction;
use Izzy\Web\Table\CompositeDeleteAction;
use Izzy\Web\Table\TableFilter;
use Izzy\Web\Table\TablePagination;
use Izzy\Web\Tables\CandlesTable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CandlesViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
		$database = $this->webApp->getDatabase();
		$repo = new CandleRepository($database);

		$filterTemplate = CandlesFilter::create($repo);

		if ($request !== null) {
			$filter = TableFilter::fromRequest($request, $filterTemplate);
			$paginationRequest = TablePagination::fromRequest($request, 50);
		} else {
			$filter = $filterTemplate;
			$paginationRequest = new TablePagination(1, 50);
		}

		$dbFilters = $this->buildDbFilters($filter);
		$sets = $repo->getFilteredGroupedSets($dbFilters);

		$total = count($sets);
		$pagination = $paginationRequest->withTotal($total);
		$pageSets = array_slice($sets, $pagination->getOffset(), $pagination->getPerPage());

		$table = CandlesTable::create();
		$table->setPagination($pagination);
		$table->addAction(new CompositeDeleteAction(
			'/cgi-bin/api.pl?action=delete_candle_set',
			[
				'exchange' => 'exchange',
				'ticker' => 'ticker',
				'marketType' => 'marketType',
				'timeframe' => 'timeframe',
			],
		));
		$table->addGlobalAction(new ClearAllGlobalAction(
			'/cgi-bin/api.pl?action=clear_all_candles',
			'Clear All Candles',
			'Delete ALL candle data? This cannot be undone.',
		));
		$table->setData($pageSets);

		$baseUrl = '/candles.jsp?' . http_build_query($filter->getQueryParams());
		$exchanges = $this->webApp->getConfiguration()->getExchangeNames();

		$body = $this->webApp->getTwig()->render('candles.htt', [
			'menu' => $this->menu,
			'filterHtml' => $filter->render(),
			'tableHtml' => $table->render(),
			'paginationHtml' => $pagination->render($baseUrl),
			'sets' => $pageSets,
			'exchanges' => $exchanges,
		]);

		$response->getBody()->write($body);
		return $response;
	}

	/**
	 * Map TableFilter values to AbstractCandleRepository short column names.
	 */
	private function buildDbFilters(TableFilter $filter): array {
		$map = [
			'exchange' => 'exchange_name',
			'ticker' => 'ticker',
			'marketType' => 'market_type',
			'timeframe' => 'timeframe',
		];
		$dbFilters = [];
		foreach ($map as $filterKey => $columnShort) {
			$val = $filter->getValue($filterKey);
			if (!empty($val)) {
				$dbFilters[$columnShort] = $val;
			}
		}
		return $dbFilters;
	}
}
