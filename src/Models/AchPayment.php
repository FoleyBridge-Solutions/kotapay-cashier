<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AchPayment
 *
 * Represents an ACH payment transaction.
 *
 * @property int $id
 * @property int $user_id
 * @property string $transaction_id
 * @property float $amount
 * @property string $status
 * @property string|null $account_name
 * @property string|null $account_type
 * @property string|null $last_four
 * @property string|null $description
 * @property string|null $order_number
 * @property \DateTime|null $effective_date
 * @property array|null $metadata
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class AchPayment extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ach_payments';

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Get the casts array.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_date' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the user that owns the payment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        $foreignKey = config('kotapay.customer_columns.foreign_key', 'user_id');
        return $this->belongsTo(config('kotapay.model'), $foreignKey);
    }

    /**
     * Get the bank account associated with this payment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * Determine if the payment is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Determine if the payment is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Determine if the payment is voided.
     *
     * @return bool
     */
    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    /**
     * Determine if the payment failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Determine if the payment can be voided.
     *
     * @return bool
     */
    public function canBeVoided(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Scope to filter pending payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter completed payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
