<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Services;

use FoleyBridgeSolutions\KotapayCashier\Exceptions\PaymentFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    /**
     * Maximum allowed payment amount in dollars.
     */
    protected const MAX_PAYMENT_AMOUNT = 100000.00;

    /**
     * The API client instance.
     */
    protected ApiClient $api;

    /**
     * Create a new payment service instance.
     *
     * @return void
     */
    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Create an ACH payment (debit from customer's account).
     *
     * @param  array  $paymentData  Payment details:
     *                              - amount: float (required) - Payment amount in dollars
     *                              - routing_number: string (required) - 9-digit routing number
     *                              - account_number: string (required) - Bank account number
     *                              - account_type: string - 'Checking' or 'Savings' (default: Checking)
     *                              - account_name: string (required) - Name on account
     *                              - description: string - Payment description (max 10 chars)
     *                              - addenda: string - Additional info (max 80 chars, default: 'PAYMENT')
     *                              - order_number: string - Your reference number (default: auto-generated UUID)
     *                              - account_name_id: string - Kotapay account name ID (default: empty)
     *                              - storage_customer_record_id: string - Kotapay storage customer record ID (default: empty)
     *                              - effective_date: string - Date to process (Y-m-d)
     *                              - idempotency_key: string - Unique key to prevent duplicate payments
     * @return array API response with transactionId
     *
     * @throws PaymentFailedException
     */
    public function createPayment(array $paymentData): array
    {
        $this->validatePaymentData($paymentData);

        // Sanitize inputs before sending to API
        $sanitizedRouting = $this->sanitizeRoutingNumber($paymentData['routing_number']);
        $sanitizedAccount = $this->sanitizeAccountNumber($paymentData['account_number']);

        $payload = [
            'amount' => $paymentData['amount'],
            'routingNumber' => $sanitizedRouting,
            'accountNumber' => $sanitizedAccount,
            'accountType' => match (strtolower($paymentData['account_type'] ?? 'checking')) {
                'savings', 's' => 'S',
                default => 'C',
            },
            'accountName' => $this->sanitizeAccountName($paymentData['account_name']),
            'description' => substr($paymentData['description'] ?? 'PAYMENT', 0, 10),
            'addenda' => substr($paymentData['addenda'] ?? 'PAYMENT', 0, 80),
            'orderNumber' => $paymentData['order_number'] ?? Str::uuid()->toString(),
            'accountNameId' => $paymentData['account_name_id'] ?? '',
            'storageCustomerRecordId' => $paymentData['storage_customer_record_id'] ?? '',
        ];

        if (! empty($paymentData['effective_date'])) {
            $payload['effectiveDate'] = $paymentData['effective_date'];
        }
        if (! empty($paymentData['application_id'])) {
            $payload['applicationId'] = $paymentData['application_id'];
        }

        // Add idempotency key to prevent duplicate payments
        $idempotencyKey = $paymentData['idempotency_key'] ?? Str::uuid()->toString();
        $payload['idempotencyKey'] = $idempotencyKey;

        try {
            $companyId = $this->api->getCompanyId();
            $response = $this->api->post("/v1/Ach/{$companyId}/payment", $payload);

            Log::info('Kotapay raw API response', $response);

            // Validate the API response status - Kotapay may return HTTP 200 with a failure in the body
            $responseStatus = $response['status'] ?? null;
            if ($responseStatus === 'fail' || $responseStatus === 'error') {
                $message = $response['message'] ?? 'Kotapay payment rejected';
                $errors = $response['data'] ?? [];
                throw new PaymentFailedException(
                    "Kotapay payment rejected: {$message}".(! empty($errors) ? ' - '.json_encode($errors) : ''),
                    $response
                );
            }

            Log::info('Kotapay ACH payment created', [
                'amount' => $paymentData['amount'],
                'account_name' => $paymentData['account_name'],
                'transaction_id' => $response['data']['transactionId'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $response;
        } catch (\Exception $e) {
            throw new PaymentFailedException(
                'Kotapay payment failed: '.$e->getMessage(),
                method_exists($e, 'getResponse') ? $e->getResponse() : [],
                0,
                $e
            );
        }
    }

    /**
     * Get a payment by transaction ID.
     *
     * @throws PaymentFailedException
     */
    public function getPayment(string $transactionId): array
    {
        // Guard: validate transaction ID format
        if (! $this->isValidTransactionId($transactionId)) {
            throw new PaymentFailedException('Invalid transaction ID format.');
        }

        try {
            $companyId = $this->api->getCompanyId();

            return $this->api->get("/v1/Ach/{$companyId}/payment/{$transactionId}");
        } catch (\Exception $e) {
            throw new PaymentFailedException(
                'Failed to get payment: '.$e->getMessage(),
                method_exists($e, 'getResponse') ? $e->getResponse() : [],
                0,
                $e
            );
        }
    }

    /**
     * Get all payments for the company.
     *
     * @throws PaymentFailedException
     */
    public function getPayments(): array
    {
        try {
            $companyId = $this->api->getCompanyId();

            return $this->api->get("/v1/Ach/{$companyId}/payment");
        } catch (\Exception $e) {
            throw new PaymentFailedException(
                'Failed to get payments: '.$e->getMessage(),
                method_exists($e, 'getResponse') ? $e->getResponse() : [],
                0,
                $e
            );
        }
    }

    /**
     * Void a pending ACH payment.
     *
     * @throws PaymentFailedException
     */
    public function voidPayment(string $transactionId): array
    {
        // Guard: validate transaction ID format
        if (! $this->isValidTransactionId($transactionId)) {
            throw new PaymentFailedException('Invalid transaction ID format.');
        }

        try {
            $companyId = $this->api->getCompanyId();
            $response = $this->api->delete("/v1/Ach/{$companyId}/payment/void/{$transactionId}");

            Log::info('Kotapay payment voided', ['transaction_id' => $transactionId]);

            return $response;
        } catch (\Exception $e) {
            throw new PaymentFailedException(
                'Failed to void payment: '.$e->getMessage(),
                method_exists($e, 'getResponse') ? $e->getResponse() : [],
                0,
                $e
            );
        }
    }

    /**
     * Validate payment data.
     *
     * @throws PaymentFailedException
     */
    protected function validatePaymentData(array $data): void
    {
        $required = ['amount', 'routing_number', 'account_number', 'account_name'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new PaymentFailedException("Missing required field: {$field}");
            }
        }

        if (! is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new PaymentFailedException('Amount must be a positive number');
        }

        // Maximum amount validation
        if ($data['amount'] > self::MAX_PAYMENT_AMOUNT) {
            throw new PaymentFailedException(
                sprintf('Amount exceeds maximum allowed ($%s)', number_format(self::MAX_PAYMENT_AMOUNT, 2))
            );
        }

        // Validate routing number format and checksum
        if (! $this->isValidRoutingNumber($data['routing_number'])) {
            throw new PaymentFailedException('Invalid routing number. Must be 9 digits with valid ABA checksum.');
        }

        // Validate account number (4-17 digits after stripping non-digits)
        $accountNumber = preg_replace('/\D/', '', $data['account_number']);
        if (strlen($accountNumber) < 4 || strlen($accountNumber) > 17) {
            throw new PaymentFailedException('Account number must be 4-17 digits');
        }

        $validAccountTypes = ['Checking', 'Savings', 'checking', 'savings', 'C', 'S'];
        if (! empty($data['account_type']) && ! in_array($data['account_type'], $validAccountTypes)) {
            throw new PaymentFailedException('Account type must be Checking or Savings');
        }

        // Validate effective_date format if provided
        if (! empty($data['effective_date'])) {
            $this->validateEffectiveDate($data['effective_date']);
        }
    }

    /**
     * Validate effective date format and value.
     *
     * @throws PaymentFailedException
     */
    protected function validateEffectiveDate(string $date): void
    {
        // Validate format (Y-m-d)
        $dateTime = \DateTime::createFromFormat('Y-m-d', $date);
        if (! $dateTime || $dateTime->format('Y-m-d') !== $date) {
            throw new PaymentFailedException('Invalid effective_date format. Expected Y-m-d (e.g., 2024-01-15)');
        }

        // Ensure date is not in the past
        $today = new \DateTime('today');
        if ($dateTime < $today) {
            throw new PaymentFailedException('Effective date cannot be in the past');
        }

        // Ensure date is not too far in the future (30 days max)
        $maxDate = new \DateTime('+30 days');
        if ($dateTime > $maxDate) {
            throw new PaymentFailedException('Effective date cannot be more than 30 days in the future');
        }
    }

    /**
     * Validate ABA routing number format and checksum.
     *
     * ABA routing numbers use a weighted checksum algorithm:
     * 3(d1 + d4 + d7) + 7(d2 + d5 + d8) + (d3 + d6 + d9) mod 10 = 0
     */
    protected function isValidRoutingNumber(string $routing): bool
    {
        // Strip any non-digits first
        $routing = preg_replace('/\D/', '', $routing);

        // Must be exactly 9 digits
        if (strlen($routing) !== 9) {
            return false;
        }

        // ABA checksum validation
        $sum = 3 * ((int) $routing[0] + (int) $routing[3] + (int) $routing[6])
             + 7 * ((int) $routing[1] + (int) $routing[4] + (int) $routing[7])
             + ((int) $routing[2] + (int) $routing[5] + (int) $routing[8]);

        return $sum % 10 === 0;
    }

    /**
     * Validate transaction ID format.
     */
    protected function isValidTransactionId(string $transactionId): bool
    {
        // Transaction IDs should be non-empty alphanumeric strings
        return ! empty($transactionId) && preg_match('/^[a-zA-Z0-9\-_]+$/', $transactionId);
    }

    /**
     * Sanitize routing number (remove non-digits).
     */
    protected function sanitizeRoutingNumber(string $routing): string
    {
        return preg_replace('/\D/', '', $routing);
    }

    /**
     * Sanitize account number (remove non-digits).
     */
    protected function sanitizeAccountNumber(string $account): string
    {
        return preg_replace('/\D/', '', $account);
    }

    /**
     * Sanitize account name (remove potentially dangerous characters).
     */
    protected function sanitizeAccountName(string $name): string
    {
        // Keep alphanumeric, spaces, and common punctuation
        return preg_replace('/[^a-zA-Z0-9\s\-\.,\'&]/', '', $name);
    }
}
