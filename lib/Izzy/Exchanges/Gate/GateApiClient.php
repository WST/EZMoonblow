<?php

namespace Izzy\Exchanges\Gate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Lightweight HTTP client for Gate.io API v4 with HMAC-SHA512 authentication.
 *
 * Replaces the gateio/gateapi-php SDK which requires Guzzle 6 and has
 * PHP 8.4 deprecation issues.
 */
class GateApiClient
{
	private const string BASE_URL = 'https://api.gateio.ws';
	private const string API_PREFIX = '/api/v4';

	private Client $http;
	private string $key;
	private string $secret;

	public function __construct(string $key, string $secret) {
		$this->key = $key;
		$this->secret = $secret;
		$this->http = new Client([
			'base_uri' => self::BASE_URL,
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		]);
	}

	/**
	 * Signed GET request.
	 *
	 * @param string $path API path without prefix (e.g. "/futures/usdt/tickers").
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON response.
	 */
	public function get(string $path, array $query = []): array {
		return $this->request('GET', $path, $query);
	}

	/**
	 * Signed POST request.
	 *
	 * @param string $path API path without prefix.
	 * @param array $body Request body (will be JSON-encoded).
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON response.
	 */
	public function post(string $path, array $body = [], array $query = []): array {
		return $this->request('POST', $path, $query, $body);
	}

	/**
	 * Signed DELETE request.
	 *
	 * @param string $path API path without prefix.
	 * @param array<string, string> $query Query parameters.
	 * @param array $body Optional request body.
	 * @return array Decoded JSON response.
	 */
	public function delete(string $path, array $query = [], array $body = []): array {
		return $this->request('DELETE', $path, $query, $body);
	}

	/**
	 * Unsigned GET request (for public endpoints).
	 *
	 * @param string $path API path without prefix.
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON response.
	 */
	public function publicGet(string $path, array $query = []): array {
		$fullPath = self::API_PREFIX . $path;

		$options = [];
		if (!empty($query)) {
			$options['query'] = $query;
		}

		$response = $this->http->request('GET', $fullPath, $options);
		$body = $response->getBody()->getContents();

		return json_decode($body, true) ?? [];
	}

	/**
	 * Execute a signed API request.
	 *
	 * Gate API v4 signature:
	 *   sign_string = METHOD\nFULL_PATH\nQUERY_STRING\nHEX(SHA512(BODY))\nTIMESTAMP
	 *   SIGN = HEX(HMAC_SHA512(secret, sign_string))
	 */
	private function request(string $method, string $path, array $query = [], array $body = []): array {
		$fullPath = self::API_PREFIX . $path;
		$timestamp = (string) time();

		$queryString = !empty($query) ? http_build_query($query) : '';
		$jsonBody = !empty($body) ? json_encode($body) : '';
		$hashedBody = hash('sha512', $jsonBody);

		$signString = implode("\n", [
			$method,
			$fullPath,
			$queryString,
			$hashedBody,
			$timestamp,
		]);

		$signature = hash_hmac('sha512', $signString, $this->secret);

		$options = [
			'headers' => [
				'KEY' => $this->key,
				'SIGN' => $signature,
				'Timestamp' => $timestamp,
			],
		];

		if (!empty($query)) {
			$options['query'] = $query;
		}

		if (!empty($body)) {
			$options['body'] = $jsonBody;
		}

		$response = $this->http->request($method, $fullPath, $options);
		$responseBody = $response->getBody()->getContents();

		return json_decode($responseBody, true) ?? [];
	}
}
