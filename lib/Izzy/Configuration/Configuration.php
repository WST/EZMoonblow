<?php

namespace Izzy\Configuration;

use DOMDocument;
use DOMXPath;
use Izzy\ConsoleApplication;
use Izzy\Database;
use Izzy\Exchanges\Bybit;
use Izzy\Interfaces\IExchangeDriver;

class Configuration
{
	private DOMDocument $document;
	private DOMXpath $xpath;
	
	public function __construct($filename) {
		$this->document = new DOMDocument();
		$this->document->load($filename);
		$this->xpath = new DOMXpath($this->document);
	}

	/**
	 * Создать драйверы бирж.
	 * @return IExchangeDriver[]
	 */
	public function connectExchanges(ConsoleApplication $application): array {
		$exchanges = $this->xpath->query('//exchanges/exchange');
		$result = [];
		foreach ($exchanges as $exchangeConfigurationNode) {
			$exchangeName = $exchangeConfigurationNode->getAttribute('name');
			$exchangeConfiguration = new ExchangeConfiguration($exchangeConfigurationNode);
			$exchange = $exchangeConfiguration->connectToExchange($application);
			if ($exchange) {
				$result[$exchange->getName()] = $exchange;
			}
		}
		return $result;
	}
	
	public function openDatabase(): Database {
		// Пока что поддерживаем только MySQL.
		$engine = $this->xpath->evaluate('string(//database/@engine)');
		if ($engine !== 'MySQL') {
			die("Only MySQL is supported");
		}
		
		// Получим реквизиты для доступа.
		$host = $this->xpath->evaluate('string(//database/host)');
		$username = $this->xpath->evaluate('string(//database/username)');
		$password = $this->xpath->evaluate('string(//database/password)');
		$dbname = $this->xpath->evaluate('string(//database/dbname)');

		return new Database($host, $dbname, $username, $password);
	}
}
