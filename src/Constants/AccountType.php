<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Constants;

/**
 * Account type constants for Kotapay ACH transactions.
 */
final class AccountType
{
    public const CHECKING = 'Checking';
    public const SAVINGS = 'Savings';

    /**
     * Get all valid account types.
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
     * Check if an account type is valid.
     *
     * @param  string  $type
     * @return bool
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
