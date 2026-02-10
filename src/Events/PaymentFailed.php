<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentFailed
 *
 * Dispatched when an ACH payment fails.
 */
class PaymentFailed
{
    use Dispatchable, SerializesModels;

    /**
     * The exception that caused the failure.
     *
     * @var \Throwable
     */
    public \Throwable $exception;

    /**
     * The billable model that attempted the payment.
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
     * Create a new event instance.
     *
     * @param  \Throwable  $exception
     * @param  mixed  $billable
     * @param  float  $amount
     * @return void
     */
    public function __construct(\Throwable $exception, $billable, float $amount)
    {
        $this->exception = $exception;
        $this->billable = $billable;
        $this->amount = $amount;
    }
}
