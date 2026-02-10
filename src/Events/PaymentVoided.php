<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentVoided
 *
 * Dispatched when an ACH payment is voided.
 */
class PaymentVoided
{
    use Dispatchable, SerializesModels;

    /**
     * The void response from the Kotapay API.
     *
     * @var array
     */
    public array $response;

    /**
     * The billable model that voided the payment.
     *
     * @var mixed
     */
    public $billable;

    /**
     * The transaction ID that was voided.
     *
     * @var string
     */
    public string $transactionId;

    /**
     * Create a new event instance.
     *
     * @param  array  $response
     * @param  mixed  $billable
     * @param  string  $transactionId
     * @return void
     */
    public function __construct(array $response, $billable, string $transactionId)
    {
        $this->response = $response;
        $this->billable = $billable;
        $this->transactionId = $transactionId;
    }
}
