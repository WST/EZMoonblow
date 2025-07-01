<?php

namespace Izzy\Configuration;

use DOMDocument;
use DOMXPath;
use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\System\Database\Database;

class Configuration
{
	private DOMDocument $document;
	private DOMXpath $xpath;
	
	public function __construct($filename) {
		$this->document = new DOMDocument();
		$this->document->load($filename);
		$this->xpath = new DOMXpath($this->document);
	}

	public static function getInstance(): static {
		return new self(IZZY_CONFIG . "/config.xml");
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

	public function connectExchange(ConsoleApplication $application, string $exchangeName): IExchangeDriver|false {
		$exchanges = $this->xpath->query('//exchanges/exchange');
		foreach ($exchanges as $exchangeConfigurationNode) {
			$configExchangeName = $exchangeConfigurationNode->getAttribute('name');
			if ($configExchangeName != $exchangeName) continue;
			$exchangeConfiguration = new ExchangeConfiguration($exchangeConfigurationNode);
			return $exchangeConfiguration->connectToExchange($application);
		}
		return false;
	}
}
