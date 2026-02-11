<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use FoleyBridgeSolutions\KotapayCashier\Exceptions\KotapayException;

class ApiClient
{
    /**
     * The base URL for the API.
     *
     * @var string
     */
    protected string $baseUrl;

    /**
     * The OAuth2 client ID.
     *
     * @var string
     */
    protected string $clientId;

    /**
     * The OAuth2 client secret.
     *
     * @var string
     */
    protected string $clientSecret;

    /**
     * The API username.
     *
     * @var string
     */
    protected string $username;

    /**
     * The API password.
     *
     * @var string
     */
    protected string $password;

    /**
     * The company ID for API requests.
     *
     * @var string
     */
    protected string $companyId;

    /**
     * Create a new API client instance.
     *
     * @param  string  $baseUrl
     * @param  string  $clientId
     * @param  string  $clientSecret
     * @param  string  $username
     * @param  string  $password
     * @param  string  $companyId
     * @return void
     * @throws KotapayException  If required credentials are missing
     */
    public function __construct(
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $username,
        string $password,
        string $companyId
    ) {
        // Defer validation if package is disabled
        if (config('kotapay.enabled', true)) {
            if (empty($clientId) || empty($clientSecret)) {
                throw new KotapayException('Kotapay OAuth2 credentials are required. Set KOTAPAY_API_CLIENT_ID and KOTAPAY_API_CLIENT_SECRET in your .env file.');
            }

            if (empty($username) || empty($password)) {
                throw new KotapayException('Kotapay API credentials are required. Set KOTAPAY_API_USERNAME and KOTAPAY_API_PASSWORD in your .env file.');
            }

            if (empty($companyId)) {
                throw new KotapayException('Kotapay company ID is required. Set KOTAPAY_API_COMPANY_ID in your .env file.');
            }
        }

        $this->baseUrl = rtrim($baseUrl ?: 'https://api.kotapay.com', '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username = $username;
        $this->password = $password;
        $this->companyId = $companyId;
    }

    /**
     * Get the company ID.
     *
     * @return string
     */
    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    /**
     * Get a valid access token, refreshing if necessary.
     *
     * Token expires in 300 seconds (5 minutes).
     * Uses Cache::remember() for atomic operation to prevent race conditions.
     *
     * @return string
     * @throws KotapayException
     */
    public function getAccessToken(): string
    {
        $cacheKey = config('kotapay.token_cache_key', 'kotapay_access_token');
        $cacheTtl = config('kotapay.token_cache_ttl', 270);

        // Use Cache::remember() for atomic operation - prevents race condition
        return Cache::remember($cacheKey, now()->addSeconds($cacheTtl), function () {
            return $this->fetchNewToken();
        });
    }

    /**
     * Fetch a new access token from the API.
     *
     * @return string
     * @throws KotapayException
     */
    protected function fetchNewToken(): string
    {
        $timeout = config('kotapay.timeout', 30);

        $response = Http::timeout($timeout)
            ->asForm()
            ->post("{$this->baseUrl}/v1/auth/token", [
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->username,
                'password' => $this->password,
            ]);

        if (!$response->successful()) {
            Log::error('Kotapay auth failed', [
                'status' => $response->status(),
                // Don't log response body - may contain sensitive data
            ]);
            throw new KotapayException('Failed to authenticate with Kotapay API. Status: ' . $response->status());
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'success') {
            throw new KotapayException('Kotapay auth failed: ' . ($data['message'] ?? 'Unknown error'));
        }

        $token = $data['data']['access_token'] ?? null;

        if (!$token) {
            throw new KotapayException('No access token in Kotapay response');
        }

        Log::info('Kotapay access token obtained', ['expires_in' => $data['data']['expires_in'] ?? 300]);

        return $token;
    }

    /**
     * Clear the cached access token.
     *
     * @return void
     */
    public function clearToken(): void
    {
        Cache::forget(config('kotapay.token_cache_key', 'kotapay_access_token'));
    }

    /**
     * Make a POST request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $data
     * @return array
     * @throws KotapayException
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->requestWithRetry('POST', $endpoint, $data);
    }

    /**
     * Make a GET request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $query
     * @return array
     * @throws KotapayException
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->requestWithRetry('GET', $endpoint, [], $query);
    }

    /**
     * Make a PUT request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $data
     * @return array
     * @throws KotapayException
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->requestWithRetry('PUT', $endpoint, $data);
    }

    /**
     * Make a PATCH request to the API.
     *
     * @param  string  $endpoint
     * @param  array  $data
     * @return array
     * @throws KotapayException
     */
    public function patch(string $endpoint, array $data = []): array
    {
        return $this->requestWithRetry('PATCH', $endpoint, $data);
    }

    /**
     * Make a DELETE request to the API.
     *
     * @param  string  $endpoint
     * @return array
     * @throws KotapayException
     */
    public function delete(string $endpoint): array
    {
        return $this->requestWithRetry('DELETE', $endpoint);
    }

    /**
     * Make a request with retry logic for transient failures.
     *
     * @param  string  $method
     * @param  string  $endpoint
     * @param  array  $data
     * @param  array  $query
     * @return array
     * @throws KotapayException
     */
    protected function requestWithRetry(string $method, string $endpoint, array $data = [], array $query = []): array
    {
        $retryEnabled = config('kotapay.retry.enabled', true);
        $maxAttempts = $retryEnabled ? config('kotapay.retry.max_attempts', 3) : 1;
        $delayMs = config('kotapay.retry.delay_ms', 100);

        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                $this->checkRateLimit();
                return $this->request($method, $endpoint, $data, $query);
            } catch (ConnectionException $e) {
                $attempts++;

                Log::warning('Kotapay API connection error', [
                    'attempt' => $attempts,
                    'max_attempts' => $maxAttempts,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= $maxAttempts) {
                    throw new KotapayException(
                        'Kotapay API connection failed after ' . $attempts . ' attempts: ' . $e->getMessage(),
                        [],
                        0,
                        $e
                    );
                }

                // Exponential backoff: 100ms, 200ms, 400ms...
                $sleepMs = $delayMs * pow(2, $attempts - 1);
                usleep($sleepMs * 1000);
            } catch (KotapayException $e) {
                $lastException = $e;
                $attempts++;

                // On 401, clear token and retry once
                if ($e->getCode() === 401 && $attempts === 1) {
                    $this->clearToken();
                    Log::warning('Kotapay token expired, refreshing', ['endpoint' => $endpoint]);
                    continue;
                }

                if (!$this->isRetryable($e) || $attempts >= $maxAttempts) {
                    throw $e;
                }

                // Exponential backoff: 100ms, 200ms, 400ms...
                $sleepMs = $delayMs * pow(2, $attempts - 1);
                usleep($sleepMs * 1000);

                Log::warning('Kotapay API retry', [
                    'attempt' => $attempts,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new KotapayException('Request failed after retries');
    }

    /**
     * Check if the exception is retryable.
     *
     * @param  KotapayException  $e
     * @return bool
     */
    protected function isRetryable(KotapayException $e): bool
    {
        $code = $e->getCode();

        // Retry on server errors, rate limits, and connection issues
        return $code >= 500 || $code === 429 || $code === 0;
    }

    /**
     * Check rate limit before making a request.
     *
     * Uses atomic Cache::increment() to prevent race conditions.
     *
     * @return void
     * @throws KotapayException  If rate limit exceeded
     */
    protected function checkRateLimit(): void
    {
        if (!config('kotapay.rate_limit.enabled', true)) {
            return;
        }

        $maxRequests = config('kotapay.rate_limit.max_requests_per_hour', 1000);
        $cacheKey = 'kotapay_rate_limit_' . date('YmdH');

        // Atomic increment - prevents race condition
        $currentCount = Cache::increment($cacheKey);

        // Set expiry on first request of the hour
        if ($currentCount === 1) {
            Cache::put($cacheKey, 1, 3600);
        }

        if ($currentCount > $maxRequests) {
            throw new KotapayException('API rate limit exceeded. Maximum ' . $maxRequests . ' requests per hour.', [], 429);
        }
    }

    /**
     * Make a request to the API.
     *
     * @param  string  $method
     * @param  string  $endpoint
     * @param  array  $data
     * @param  array  $query
     * @return array
     * @throws KotapayException
     */
    protected function request(string $method, string $endpoint, array $data = [], array $query = []): array
    {
        $token = $this->getAccessToken();
        $timeout = config('kotapay.timeout', 30);

        $request = Http::timeout($timeout)
            ->withToken($token)
            ->acceptJson();

        if (!empty($query)) {
            $request = $request->withQueryParameters($query);
        }

        $url = "{$this->baseUrl}{$endpoint}";

        $response = match ($method) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'PATCH' => $request->patch($url, $data),
            'DELETE' => $request->delete($url),
            default => throw new KotapayException("Unsupported HTTP method: {$method}"),
        };

        $result = $response->json() ?? [];

        if (!$response->successful()) {
            $message = $result['message'] ?? $response->body();
            Log::error('Kotapay API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'message' => $message,
            ]);
            throw new KotapayException("Kotapay API error: {$message}", $result, $response->status());
        }

        return $result;
    }

    /**
     * Test the API connection.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $this->getAccessToken();
            return true;
        } catch (\Exception $e) {
            Log::error('Kotapay connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
