<?php

namespace Izzy\Configuration;

use DOMDocument;
use DOMXPath;
use Izzy\AbstractApplications\IzzyApplication;
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
	public function connectExchanges(IzzyApplication $application): array {
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
	
	public function getDatabaseHost(): string {
		return $this->xpath->evaluate('string(//database/host)');
	}
	
	public function getDatabaseName(): string {
		return $this->xpath->evaluate('string(//database/dbname)');
	}
	
	public function getDatabaseUser(): string {
		return $this->xpath->evaluate('string(//database/username)');
	}
	
	public function getDatabasePassword(): string {
		return $this->xpath->evaluate('string(//database/password)');
	}
	
	public function getTelegramToken(): string {
		return $this->xpath->evaluate('string(//telegram/token)');
	}
	
	public function getTelegramChatId(): int {
		return intval($this->xpath->evaluate('string(//telegram/chat_id)'));
	}
	
	public function openDatabase(): Database {
		// Пока что поддерживаем только MySQL.
		$engine = $this->xpath->evaluate('string(//database/@engine)');
		if ($engine !== 'MySQL') {
			die("Only MySQL is supported");
		}
		
		// Получим реквизиты для доступа.
		$host = $this->getDatabaseHost();
		$username = $this->getDatabaseUser();
		$password = $this->getDatabasePassword();
		$dbname = $this->getDatabaseName();

		return new Database($host, $dbname, $username, $password);
	}

	public function connectExchange(IzzyApplication $application, string $exchangeName): IExchangeDriver|false {
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
