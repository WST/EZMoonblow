<?php

namespace Izzy\Configuration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Izzy\AbstractApplications\IzzyApplication;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Financial\Pair;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\System\Database\Database;

/**
 * Main configuration class for EZMoonblow.
 *
 * Handles loading and parsing the XML configuration file,
 * providing access to database, exchange, and other settings.
 */
class Configuration
{
	private DOMDocument $document;
	private DOMXpath $xpath;

	/**
	 * Create a new Configuration instance.
	 *
	 * @param string $filename Path to the configuration XML file.
	 */
	public function __construct(string $filename) {
		$this->document = new DOMDocument();
		$this->document->load($filename);
		$this->xpath = new DOMXpath($this->document);
	}

	/**
	 * Get the singleton Configuration instance.
	 *
	 * @return static Configuration instance loaded from the default config file.
	 */
	public static function getInstance(): static {
		return new self(IZZY_CONFIG_XML);
	}

	/**
	 * Connect to all configured exchanges.
	 *
	 * @param IzzyApplication $application Application instance for dependency injection.
	 * @return IExchangeDriver[] Array of connected exchange drivers, keyed by exchange name.
	 */
	public function connectExchanges(IzzyApplication $application): array {
		$exchanges = $this->xpath->query('//exchanges/exchange');
		$result = [];
		foreach ($exchanges as $exchangeConfigurationNode) {
			$exchangeConfiguration = new ExchangeConfiguration($exchangeConfigurationNode);
			$exchange = $exchangeConfiguration->connectToExchange($application);
			if ($exchange) {
				$result[$exchange->getName()] = $exchange;
			}
		}
		return $result;
	}

	/**
	 * Connect to a specific exchange by name.
	 *
	 * @param IzzyApplication $application Application instance for dependency injection.
	 * @param string $exchangeName Name of the exchange to connect to.
	 * @return IExchangeDriver|false Connected exchange driver or false if not found/disabled.
	 */
	public function connectExchange(IzzyApplication $application, string $exchangeName): IExchangeDriver|false {
		$exchangeConfig = $this->getExchangeConfiguration($exchangeName);
		if (!$exchangeConfig) {
			return false;
		}
		return $exchangeConfig->connectToExchange($application);
	}

	/**
	 * Get exchange configuration by name.
	 *
	 * @param string $exchangeName Name of the exchange.
	 * @return ExchangeConfiguration|null Exchange configuration or null if not found.
	 */
	public function getExchangeConfiguration(string $exchangeName): ?ExchangeConfiguration {
		$exchanges = $this->xpath->query('//exchanges/exchange');
		foreach ($exchanges as $exchangeNode) {
			if (!$exchangeNode instanceof DOMElement) {
				continue;
			}
			if ($exchangeNode->getAttribute('name') === $exchangeName) {
				return new ExchangeConfiguration($exchangeNode);
			}
		}
		return null;
	}

	/**
	 * Get available pair tickers for a specific exchange and market type.
	 *
	 * This is a convenience method that delegates to ExchangeConfiguration.
	 *
	 * @param string $exchangeName Name of the exchange.
	 * @param MarketTypeEnum $marketType Market type.
	 * @return string[] Array of ticker strings.
	 */
	public function getAvailablePairTickers(string $exchangeName, MarketTypeEnum $marketType): array {
		$exchangeConfig = $this->getExchangeConfiguration($exchangeName);
		if (!$exchangeConfig) {
			return [];
		}
		return $exchangeConfig->getPairTickers($marketType);
	}

	/**
	 * Get all pairs with indicators configured across all enabled exchanges.
	 *
	 * @return Pair[] Array of Pair objects with indicators.
	 */
	public function getPairsWithIndicators(): array {
		$result = [];
		$exchanges = $this->xpath->query('//exchanges/exchange');

		foreach ($exchanges as $exchangeNode) {
			if (!$exchangeNode instanceof DOMElement) {
				continue;
			}

			// Skip disabled exchanges
			if ($exchangeNode->getAttribute('enabled') !== 'yes') {
				continue;
			}

			$exchangeConfig = new ExchangeConfiguration($exchangeNode);
			$pairsWithIndicators = $exchangeConfig->getPairsWithIndicators();
			$result = array_merge($result, $pairsWithIndicators);
		}

		return $result;
	}

	/**
	 * Get pairs configured for backtesting (have backtest_days on strategy) from connected exchanges.
	 *
	 * @param array<string, IExchangeDriver> $exchanges Connected exchange drivers keyed by exchange name.
	 * @return array<int, array{exchange: IExchangeDriver, pair: Pair}> List of exchange and pair entries.
	 */
	public function getPairsForBacktest(array $exchanges): array {
		$result = [];
		foreach ($exchanges as $exchangeName => $driver) {
			$exchangeConfig = $this->getExchangeConfiguration($exchangeName);
			if (!$exchangeConfig) {
				continue;
			}
			$spotPairs = $exchangeConfig->getSpotPairs($driver);
			$futuresPairs = $exchangeConfig->getFuturesPairs($driver);
			foreach (array_merge($spotPairs, $futuresPairs) as $pair) {
				if ($pair->getBacktestDays() !== null) {
					$result[] = ['exchange' => $driver, 'pair' => $pair];
				}
			}
		}
		return $result;
	}

	/**
	 * Get database host from configuration.
	 * Respects IZZY_DB_HOST env var (useful inside Docker containers
	 * where 127.0.0.1 from config.xml would point to the container itself).
	 *
	 * @return string Database host.
	 */
	public function getDatabaseHost(): string {
		$envHost = getenv('IZZY_DB_HOST');
		if ($envHost !== false && $envHost !== '') {
			return $envHost;
		}
		return $this->xpath->evaluate('string(//database/host)');
	}

	/**
	 * Get database name from configuration.
	 * @return string Database name.
	 */
	public function getDatabaseName(): string {
		return $this->xpath->evaluate('string(//database/dbname)');
	}

	/**
	 * Get database username from configuration.
	 * @return string Database username.
	 */
	public function getDatabaseUser(): string {
		return $this->xpath->evaluate('string(//database/username)');
	}

	/**
	 * Get database password from configuration.
	 * @return string Database password.
	 */
	public function getDatabasePassword(): string {
		return $this->xpath->evaluate('string(//database/password)');
	}

	/**
	 * Get database port from configuration.
	 * Respects IZZY_DB_PORT env var.
	 * Falls back to 3306 if not specified.
	 *
	 * @return int Database port.
	 */
	public function getDatabasePort(): int {
		$envPort = getenv('IZZY_DB_PORT');
		if ($envPort !== false && $envPort !== '') {
			return (int) $envPort;
		}
		$port = $this->xpath->evaluate('string(//database/port)');
		return $port !== '' ? (int) $port : 3306;
	}

	/**
	 * Get Telegram bot token from configuration.
	 * @return string Telegram bot token.
	 */
	public function getTelegramToken(): string {
		return $this->xpath->evaluate('string(//telegram/token)');
	}

	/**
	 * Get Telegram chat ID from configuration.
	 * @return int Telegram chat ID.
	 */
	public function getTelegramChatId(): int {
		return intval($this->xpath->evaluate('string(//telegram/chat_id)'));
	}

	/**
	 * Get web interface password from configuration.
	 * @return string Web password.
	 */
	public function getWebPassword(): string {
		return $this->xpath->evaluate('string(//web/password)');
	}

	/**
	 * Create and return a database connection.
	 *
	 * Currently only MySQL is supported.
	 *
	 * @return Database Connected database instance.
	 */
	public function openDatabase(): Database {
		// Currently only MySQL is supported.
		$engine = $this->xpath->evaluate('string(//database/@engine)');
		if ($engine !== 'MySQL') {
			die("Only MySQL is supported");
		}

		// Get connection credentials.
		$host = $this->getDatabaseHost();
		$username = $this->getDatabaseUser();
		$password = $this->getDatabasePassword();
		$dbname = $this->getDatabaseName();
		$port = $this->getDatabasePort();

		return new Database($host, $dbname, $username, $password, $port);
	}
}
