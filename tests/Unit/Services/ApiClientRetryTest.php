<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Tests\Unit\Services;

use FoleyBridgeSolutions\KotapayCashier\Exceptions\AmbiguousPaymentOutcomeException;
use FoleyBridgeSolutions\KotapayCashier\Exceptions\KotapayException;
use FoleyBridgeSolutions\KotapayCashier\Services\ApiClient;
use FoleyBridgeSolutions\KotapayCashier\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Regression coverage for the TP-F5B60CA1 double-debit incident: a
 * client-side timeout on ACH origination must never be blindly retried,
 * since we cannot tell whether Kotapay already accepted the request.
 */
class ApiClientRetryTest extends TestCase
{
    private const BASE_URL = 'https://api.test.kotapay.com';

    protected function makeClient(): ApiClient
    {
        return new ApiClient(
            self::BASE_URL,
            'client-id',
            'client-secret',
            'username',
            'password',
            'company-123'
        );
    }

    protected function fakeAuthTokenResponse(): array
    {
        return [
            self::BASE_URL.'/v1/auth/token' => Http::response([
                'status' => 'success',
                'data' => ['access_token' => 'test-token', 'expires_in' => 300],
            ], 200),
        ];
    }

    public function test_non_retryable_post_throws_ambiguous_exception_after_a_single_timeout(): void
    {
        $callCount = 0;

        Http::fake($this->fakeAuthTokenResponse() + [
            self::BASE_URL.'/v1/Ach/company-123/payment' => function () use (&$callCount) {
                $callCount++;
                throw new ConnectionException('Operation timed out after 30001 milliseconds with 0 bytes received');
            },
        ]);

        $client = $this->makeClient();

        try {
            $client->post('/v1/Ach/company-123/payment', ['amount' => 650], retryOnConnectionError: false);
            $this->fail('Expected AmbiguousPaymentOutcomeException was not thrown.');
        } catch (AmbiguousPaymentOutcomeException $e) {
            // expected
        }

        $this->assertSame(
            1,
            $callCount,
            'A non-retryable request must not be resent after an ambiguous (timed-out) outcome.'
        );
    }

    public function test_retryable_post_still_retries_on_timeout_and_exhausts_max_attempts(): void
    {
        config(['kotapay.retry.max_attempts' => 3, 'kotapay.retry.delay_ms' => 1]);

        $callCount = 0;

        Http::fake($this->fakeAuthTokenResponse() + [
            self::BASE_URL.'/v1/Reports/pbr' => function () use (&$callCount) {
                $callCount++;
                throw new ConnectionException('Operation timed out after 30001 milliseconds with 0 bytes received');
            },
        ]);

        $client = $this->makeClient();

        try {
            $client->post('/v1/Reports/pbr', ['type' => 'x']);
            $this->fail('Expected KotapayException was not thrown.');
        } catch (KotapayException $e) {
            $this->assertNotInstanceOf(AmbiguousPaymentOutcomeException::class, $e);
        }

        $this->assertSame(
            3,
            $callCount,
            'A retryable (safe) request should still retry on timeout up to kotapay.retry.max_attempts.'
        );
    }
}
