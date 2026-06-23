<?php

declare(strict_types=1);

namespace App\Casts;

use App\Domain\Money\ValueObjects\Currency;
use App\Domain\Money\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<Money, Money>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return new Money((int) $value, Currency::of($attributes['currency']));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof Money) {
            throw new InvalidArgumentException("Attribute [{$key}] must be set to a Money instance.");
        }

        return [
            $key => $value->amount,
            'currency' => $value->currency->code,
        ];
    }
}
