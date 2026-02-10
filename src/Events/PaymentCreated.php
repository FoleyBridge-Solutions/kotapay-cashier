<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentCreated
 *
 * Dispatched when an ACH payment is successfully created.
 */
class PaymentCreated
{
    use Dispatchable, SerializesModels;

    /**
     * The payment response from the Kotapay API.
     *
     * @var array
     */
    public array $response;

    /**
     * The billable model that made the payment.
     *
     * @var mixed
     */
    public $billable;

    /**
     * The payment amount in dollars.
     *
     * @var float
     */
    public float $amount;

    /**
     * The transaction ID from Kotapay.
     *
     * @var string|null
     */
    public ?string $transactionId;

    /**
     * Create a new event instance.
     *
     * @param  array  $response
     * @param  mixed  $billable
     * @param  float  $amount
     * @return void
     */
    public function __construct(array $response, $billable, float $amount)
    {
        $this->response = $response;
        $this->billable = $billable;
        $this->amount = $amount;
        $this->transactionId = $response['data']['transactionId'] ?? null;
    }
}
