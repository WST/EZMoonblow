<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Backtest\OptimizationSuggestionRecord;
use Izzy\Web\Table\TablePagination;
use Izzy\Web\Tables\OptimizationSuggestionsTable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OptimizationsViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
		$database = $this->webApp->getDatabase();

		$paginationRequest = $request !== null
			? TablePagination::fromRequest($request, 25)
			: new TablePagination(1, 25);

		$total = OptimizationSuggestionRecord::countFiltered($database);
		$pagination = $paginationRequest->withTotal($total);

		$records = OptimizationSuggestionRecord::loadFiltered(
			$database,
			[],
			'',
			$pagination->getPerPage(),
			$pagination->getOffset(),
		);
		$results = array_map(fn(OptimizationSuggestionRecord $r) => $r->toArray(), $records);

		$table = OptimizationSuggestionsTable::create();
		$table->setPagination($pagination);
		$table->setRowDataAttributes(fn($row, $i) => ['data-idx' => $i]);
		$table->setData($results);

		$baseUrl = '/optimizations.jsp?';

		$body = $this->webApp->getTwig()->render('optimizations.htt', [
			'menu' => $this->menu,
			'tableHtml' => $table->render(),
			'paginationHtml' => $pagination->render($baseUrl),
			'results' => $results,
			'total' => $total,
		]);
		$response->getBody()->write($body);
		return $response;
	}
}
