<?php

namespace Izzy\Configuration;

use DOMDocument;
use DOMXPath;
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
	public function connectExchanges(): array {
		$exchanges = $this->xpath->query('//exchanges/exchange');
		print_r($exchanges);
		
		$result = [];
		foreach ($exchanges as $exchangeConfigurationNode) {
			$exchangeName = $exchangeConfigurationNode->getAttribute('name');
			if(!class_exists($exchangeName)) continue;
			$exchangeConfiguration = new ExchangeConfiguration($exchangeConfigurationNode);
			$result[$exchangeName] = new $exchangeName($exchangeConfiguration);
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
