<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string|null $credit_limit
 * @property int $credit_days
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 */
#[Fillable(['credit_limit', 'credit_days', 'business_id', 'name', 'phone', 'email'])]
class Customer extends Model
{
    protected $attributes = ['credit_days' => 30];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'credit_days' => 'integer'];
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim($phone)) ?? '';
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        if (preg_match('/^0[0-9]{9}$/', $phone)) {
            $phone = '+94'.substr($phone, 1);
        }
        if (preg_match('/^94[0-9]{9}$/', $phone)) {
            $phone = '+'.$phone;
        }

        return $phone;
    }

    /** @return list<string> */
    public static function phoneAliases(string $phone): array
    {
        $normalized = self::normalizePhone($phone);
        if (preg_match('/^\+94[0-9]{9}$/', $normalized)) {
            return [$normalized, '0'.substr($normalized, 3), substr($normalized, 1), '00'.substr($normalized, 1)];
        }

        return [$normalized];
    }

    /** @return list<string> */
    public static function phoneSearchTerms(string $phone): array
    {
        $terms = self::phoneAliases($phone);
        $normalized = self::normalizePhone($phone);
        if (preg_match('/^0[1-9][0-9]{0,8}$/', $normalized)) {
            $terms[] = '+94'.substr($normalized, 1);
        }
        if (str_starts_with($normalized, '+94')) {
            $terms[] = '0'.substr($normalized, 3);
        }

        return array_values(array_unique($terms));
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
