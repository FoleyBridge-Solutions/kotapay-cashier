<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Constants;

/**
 * Account type constants for Kotapay ACH transactions.
 *
 * The Kotapay API expects single-character values: 'C' (Checking) or 'S' (Savings).
 * Input validation accepts full words ('Checking'/'Savings') which are converted
 * to the API format in PaymentService::createPayment().
 */
final class AccountType
{
    /** API value for checking accounts. */
    public const CHECKING = 'C';

    /** API value for savings accounts. */
    public const SAVINGS = 'S';

    /** Input-friendly alias for checking. */
    public const CHECKING_LABEL = 'Checking';

    /** Input-friendly alias for savings. */
    public const SAVINGS_LABEL = 'Savings';

    /**
     * Get all valid account types (API values).
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::CHECKING,
            self::SAVINGS,
        ];
    }

    /**
     * Get all valid input values (includes labels and API values).
     *
     * @return array<string>
     */
    public static function allValid(): array
    {
        return [
            self::CHECKING,
            self::SAVINGS,
            self::CHECKING_LABEL,
            self::SAVINGS_LABEL,
            'checking',
            'savings',
        ];
    }

    /**
     * Check if an account type is valid (accepts both API values and labels).
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::allValid(), true);
    }
}
