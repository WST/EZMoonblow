<?php

namespace Izzy\Exchanges\KuCoin;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Lightweight HTTP client for KuCoin API with HMAC-SHA256 + Base64 authentication.
 *
 * Supports both spot (api.kucoin.com) and futures (api-futures.kucoin.com)
 * endpoints through separate method families.
 */
class KuCoinApiClient
{
	private const string SPOT_BASE_URL = 'https://api.kucoin.com';
	private const string FUTURES_BASE_URL = 'https://api-futures.kucoin.com';

	private Client $spotHttp;
	private Client $futuresHttp;
	private string $key;
	private string $secret;
	private string $passphrase;

	public function __construct(string $key, string $secret, string $passphrase) {
		$this->key = $key;
		$this->secret = $secret;
		$this->passphrase = $passphrase;

		$defaultHeaders = [
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
		];

		$this->spotHttp = new Client([
			'base_uri' => self::SPOT_BASE_URL,
			'timeout' => 30,
			'headers' => $defaultHeaders,
		]);

		$this->futuresHttp = new Client([
			'base_uri' => self::FUTURES_BASE_URL,
			'timeout' => 30,
			'headers' => $defaultHeaders,
		]);
	}

	/**
	 * Signed GET request to spot API.
	 *
	 * @param string $path Full API path (e.g. "/api/v1/accounts").
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function get(string $path, array $query = []): array {
		return $this->signedRequest($this->spotHttp, 'GET', $path, $query);
	}

	/**
	 * Signed POST request to spot API.
	 *
	 * @param string $path Full API path.
	 * @param array $body Request body (will be JSON-encoded).
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function post(string $path, array $body = [], array $query = []): array {
		return $this->signedRequest($this->spotHttp, 'POST', $path, $query, $body);
	}

	/**
	 * Signed DELETE request to spot API.
	 *
	 * @param string $path Full API path.
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function delete(string $path, array $query = []): array {
		return $this->signedRequest($this->spotHttp, 'DELETE', $path, $query);
	}

	/**
	 * Unsigned GET request to spot API (public endpoints).
	 *
	 * @param string $path Full API path.
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function publicGet(string $path, array $query = []): array {
		return $this->publicRequest($this->spotHttp, $path, $query);
	}

	/**
	 * Signed GET request to futures API.
	 *
	 * @param string $path Full API path (e.g. "/api/v1/position").
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function futuresGet(string $path, array $query = []): array {
		return $this->signedRequest($this->futuresHttp, 'GET', $path, $query);
	}

	/**
	 * Signed POST request to futures API.
	 *
	 * @param string $path Full API path.
	 * @param array $body Request body (will be JSON-encoded).
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function futuresPost(string $path, array $body = [], array $query = []): array {
		return $this->signedRequest($this->futuresHttp, 'POST', $path, $query, $body);
	}

	/**
	 * Signed DELETE request to futures API.
	 *
	 * @param string $path Full API path.
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function futuresDelete(string $path, array $query = []): array {
		return $this->signedRequest($this->futuresHttp, 'DELETE', $path, $query);
	}

	/**
	 * Unsigned GET request to futures API (public endpoints).
	 *
	 * @param string $path Full API path.
	 * @param array<string, string> $query Query parameters.
	 * @return array Decoded JSON data.
	 */
	public function futuresPublicGet(string $path, array $query = []): array {
		return $this->publicRequest($this->futuresHttp, $path, $query);
	}

	/**
	 * Execute a signed API request.
	 *
	 * KuCoin API v2 signature:
	 *   str_to_sign = TIMESTAMP + METHOD + ENDPOINT(?QUERY) + BODY
	 *   SIGN = Base64(HMAC-SHA256(secret, str_to_sign))
	 *   PASSPHRASE = Base64(HMAC-SHA256(secret, passphrase))
	 */
	private function signedRequest(Client $http, string $method, string $path, array $query = [], array $body = []): array {
		$timestamp = (string) intval(microtime(true) * 1000);

		$endpoint = $path;
		if (!empty($query)) {
			$endpoint .= '?' . http_build_query($query);
		}
		$jsonBody = !empty($body) ? json_encode($body) : '';

		$signString = $timestamp . $method . $endpoint . $jsonBody;
		$signature = base64_encode(hash_hmac('sha256', $signString, $this->secret, true));
		$encryptedPassphrase = base64_encode(hash_hmac('sha256', $this->passphrase, $this->secret, true));

		$options = [
			'headers' => [
				'KC-API-KEY' => $this->key,
				'KC-API-SIGN' => $signature,
				'KC-API-TIMESTAMP' => $timestamp,
				'KC-API-PASSPHRASE' => $encryptedPassphrase,
				'KC-API-KEY-VERSION' => '2',
			],
		];

		if (!empty($query)) {
			$options['query'] = $query;
		}

		if (!empty($body)) {
			$options['body'] = $jsonBody;
		}

		$response = $http->request($method, $path, $options);
		return $this->decodeResponse($response->getBody()->getContents());
	}

	/**
	 * Execute an unsigned (public) GET request.
	 */
	private function publicRequest(Client $http, string $path, array $query = []): array {
		$options = [];
		if (!empty($query)) {
			$options['query'] = $query;
		}

		$response = $http->request('GET', $path, $options);
		return $this->decodeResponse($response->getBody()->getContents());
	}

	/**
	 * Decode KuCoin API response and extract the data payload.
	 *
	 * KuCoin wraps all responses in {"code": "200000", "data": ...}.
	 * Throws RuntimeException on API-level errors.
	 */
	private function decodeResponse(string $body): array {
		$decoded = json_decode($body, true) ?? [];

		if (isset($decoded['code']) && $decoded['code'] !== '200000') {
			throw new RuntimeException(
				"KuCoin API error {$decoded['code']}: " . ($decoded['msg'] ?? 'Unknown error')
			);
		}

		$data = $decoded['data'] ?? $decoded;
		return is_array($data) ? $data : [];
	}
}
