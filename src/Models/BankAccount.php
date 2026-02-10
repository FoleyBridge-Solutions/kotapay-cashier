<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * BankAccount
 *
 * Represents a stored bank account for ACH payments.
 *
 * @property int $id
 * @property int $user_id
 * @property string $account_name
 * @property string $account_type
 * @property string $last_four
 * @property string|null $routing_last_four
 * @property bool $is_default
 * @property bool $is_verified
 * @property array|null $metadata
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class BankAccount extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'bank_accounts';

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
            'is_default' => 'boolean',
            'is_verified' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the user that owns the bank account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        $foreignKey = config('kotapay.customer_columns.foreign_key', 'user_id');
        return $this->belongsTo(config('kotapay.model'), $foreignKey);
    }

    /**
     * Get the payments for this bank account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function payments(): HasMany
    {
        return $this->hasMany(AchPayment::class);
    }

    /**
     * Mark this bank account as the default.
     *
     * @return $this
     * @throws \RuntimeException If user relationship is not found
     */
    public function makeDefault(): static
    {
        return DB::transaction(function () {
            // Guard: Ensure user relationship exists
            if (!$this->user) {
                throw new \RuntimeException(
                    'Cannot make bank account default: user relationship not found'
                );
            }

            // Unset all other bank accounts as default
            $this->user->bankAccounts()->update(['is_default' => false]);

            // Set this one as default
            $this->is_default = true;
            $this->save();

            return $this;
        });
    }

    /**
     * Mark this bank account as verified.
     *
     * @return $this
     */
    public function markAsVerified(): static
    {
        $this->is_verified = true;
        $this->save();

        return $this;
    }

    /**
     * Get the display name for this bank account.
     *
     * @return string
     */
    public function displayName(): string
    {
        return sprintf(
            '%s %s ****%s',
            $this->account_name,
            $this->account_type,
            $this->last_four
        );
    }

    /**
     * Scope to filter verified bank accounts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }
}
