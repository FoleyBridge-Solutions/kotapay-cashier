<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Tests\Unit\Constants;

use PHPUnit\Framework\TestCase;
use FoleyBridgeSolutions\KotapayCashier\Constants\AccountType;
use FoleyBridgeSolutions\KotapayCashier\Constants\TransactionStatus;

class ConstantsTest extends TestCase
{
    public function test_account_type_constants_exist(): void
    {
        $this->assertEquals('Checking', AccountType::CHECKING);
        $this->assertEquals('Savings', AccountType::SAVINGS);
    }

    public function test_account_type_all_returns_array(): void
    {
        $all = AccountType::all();
        $this->assertIsArray($all);
        $this->assertCount(2, $all);
        $this->assertContains('Checking', $all);
        $this->assertContains('Savings', $all);
    }

    public function test_account_type_is_valid(): void
    {
        $this->assertTrue(AccountType::isValid('Checking'));
        $this->assertTrue(AccountType::isValid('Savings'));
        $this->assertFalse(AccountType::isValid('Money Market'));
        $this->assertFalse(AccountType::isValid('checking')); // Case sensitive
    }

    public function test_transaction_status_constants_exist(): void
    {
        $this->assertEquals('pending', TransactionStatus::PENDING);
        $this->assertEquals('processing', TransactionStatus::PROCESSING);
        $this->assertEquals('completed', TransactionStatus::COMPLETED);
        $this->assertEquals('failed', TransactionStatus::FAILED);
        $this->assertEquals('voided', TransactionStatus::VOIDED);
        $this->assertEquals('returned', TransactionStatus::RETURNED);
    }

    public function test_transaction_status_all_returns_array(): void
    {
        $all = TransactionStatus::all();
        $this->assertIsArray($all);
        $this->assertCount(6, $all);
    }

    public function test_transaction_status_is_valid(): void
    {
        $this->assertTrue(TransactionStatus::isValid('pending'));
        $this->assertTrue(TransactionStatus::isValid('completed'));
        $this->assertTrue(TransactionStatus::isValid('returned'));
        $this->assertFalse(TransactionStatus::isValid('cancelled'));
        $this->assertFalse(TransactionStatus::isValid('Pending')); // Case sensitive
    }

    public function test_transaction_status_is_terminal(): void
    {
        // Terminal statuses - transaction is finalized
        $this->assertTrue(TransactionStatus::isTerminal('completed'));
        $this->assertTrue(TransactionStatus::isTerminal('failed'));
        $this->assertTrue(TransactionStatus::isTerminal('voided'));
        $this->assertTrue(TransactionStatus::isTerminal('returned'));

        // Non-terminal statuses - still in progress
        $this->assertFalse(TransactionStatus::isTerminal('pending'));
        $this->assertFalse(TransactionStatus::isTerminal('processing'));
    }

    public function test_transaction_status_is_successful(): void
    {
        $this->assertTrue(TransactionStatus::isSuccessful('completed'));
        $this->assertFalse(TransactionStatus::isSuccessful('pending'));
        $this->assertFalse(TransactionStatus::isSuccessful('failed'));
        $this->assertFalse(TransactionStatus::isSuccessful('voided'));
        $this->assertFalse(TransactionStatus::isSuccessful('returned'));
    }
}
