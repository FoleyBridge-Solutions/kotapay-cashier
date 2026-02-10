<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Traits;

use FoleyBridgeSolutions\KotapayCashier\Services\PaymentService;
use FoleyBridgeSolutions\KotapayCashier\Exceptions\PaymentFailedException;

trait AchBillable
{
    /**
     * Charge the customer via ACH using Kotapay.
     *
     * @param  array  $bankDetails Bank account details:
     *   - routing_number: string (required) - 9-digit routing number
     *   - account_number: string (required) - Bank account number
     *   - account_type: string - 'Checking' or 'Savings' (default: Checking)
     *   - account_name: string (required) - Name on account
     * @param  int  $amount Amount in cents
     * @param  array  $options Additional options:
     *   - description: string - Payment description (max 10 chars)
     *   - addenda: string - Additional info (max 80 chars)
     *   - effective_date: string - Date to process (Y-m-d)
     *   - order_number: string - Your reference number
     * @return array Kotapay API response with transactionId
     * @throws PaymentFailedException
     */
    public function chargeAch(array $bankDetails, int $amount, array $options = []): array
    {
        $paymentService = app(PaymentService::class);

        $paymentData = array_merge($bankDetails, $options, [
            'amount' => $amount / 100, // Convert cents to dollars
        ]);

        return $paymentService->createPayment($paymentData);
    }

    /**
     * Void a pending ACH payment.
     *
     * @param  string  $transactionId
     * @return array
     * @throws PaymentFailedException
     */
    public function voidAchPayment(string $transactionId): array
    {
        $paymentService = app(PaymentService::class);

        return $paymentService->voidPayment($transactionId);
    }

    /**
     * Get the status of an ACH payment.
     *
     * @param  string  $transactionId
     * @return array
     * @throws PaymentFailedException
     */
    public function getAchPaymentStatus(string $transactionId): array
    {
        $paymentService = app(PaymentService::class);

        return $paymentService->getPayment($transactionId);
    }

    /**
     * Charge ACH using a saved payment method.
     *
     * @param  mixed  $paymentMethod  Payment method with routing_number, account_number, account_type, account_name properties
     * @param  int  $amount Amount in cents
     * @param  array  $options Additional options
     * @return array
     * @throws PaymentFailedException
     */
    public function chargeAchWithPaymentMethod($paymentMethod, int $amount, array $options = []): array
    {
        if (!$paymentMethod) {
            throw new PaymentFailedException('Payment method is required.');
        }

        if (!is_object($paymentMethod)) {
            throw new PaymentFailedException('Invalid payment method type. Expected object, got: ' . gettype($paymentMethod));
        }

        if (!isset($paymentMethod->type) || $paymentMethod->type !== 'ach') {
            throw new PaymentFailedException('Payment method is not an ACH/bank account');
        }

        $bankDetails = [
            'routing_number' => $paymentMethod->routing_number,
            'account_number' => $paymentMethod->account_number,
            'account_type' => $paymentMethod->account_type ?? 'Checking',
            'account_name' => $paymentMethod->account_name ?? ($this->name ?? 'Account Holder'),
        ];

        return $this->chargeAch($bankDetails, $amount, $options);
    }
}
