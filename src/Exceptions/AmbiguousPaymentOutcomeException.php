<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Exceptions;

/**
 * Thrown when a non-idempotent money-movement request (e.g. ACH origination)
 * times out with no response received. We do not know whether Kotapay
 * received and processed the request before the connection dropped, so it
 * must never be silently retried — the caller has to treat the outcome as
 * unknown and verify manually before attempting the charge again.
 */
class AmbiguousPaymentOutcomeException extends KotapayException
{
    //
}
