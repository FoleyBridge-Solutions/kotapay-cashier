<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Constants;

/**
 * Transaction status constants for Kotapay ACH transactions.
 */
final class TransactionStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const VOIDED = 'voided';
    public const RETURNED = 'returned';

    /**
     * Get all valid transaction statuses.
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::VOIDED,
            self::RETURNED,
        ];
    }

    /**
     * Check if a transaction status is valid.
     *
     * @param  string  $status
     * @return bool
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Check if a transaction is in a terminal (final) state.
     *
     * @param  string  $status
     * @return bool
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, [
            self::COMPLETED,
            self::FAILED,
            self::VOIDED,
            self::RETURNED,
        ], true);
    }

    /**
     * Alias for isTerminal - check if a transaction is in a final state.
     *
     * @param  string  $status
     * @return bool
     */
    public static function isFinal(string $status): bool
    {
        return self::isTerminal($status);
    }

    /**
     * Check if a transaction was successful.
     *
     * @param  string  $status
     * @return bool
     */
    public static function isSuccessful(string $status): bool
    {
        return $status === self::COMPLETED;
    }
}
