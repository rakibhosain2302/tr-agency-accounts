<?php

namespace Nit\AgraniAccounts\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Exact money arithmetic (never floats). Works with every brick/math version Laravel 13 allows: 1.x names
 * the rounding mode Unnecessary, older releases UNNECESSARY.
 */
final class Decimal
{
    /**
     * The value with exactly $scale decimals, or null when it is not a number or has more decimals.
     */
    public static function exact(string|int|float|null $value, int $scale = 2): ?string
    {
        if ($value === null || $value === '') {
            return (string) BigDecimal::zero()->toScale($scale);
        }

        try {
            return (string) BigDecimal::of((string) $value)->toScale($scale, self::unnecessary());
        } catch (MathException) {
            return null;
        }
    }

    public static function isPositive(string $value): bool
    {
        return BigDecimal::of($value)->isPositive();
    }

    public static function isNegative(string $value): bool
    {
        return BigDecimal::of($value)->isNegative();
    }

    /** @param  iterable<string>  $values */
    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus((string) $value);
        }

        return (string) $total->toScale($scale);
    }

    private static function unnecessary(): RoundingMode
    {
        $name = defined(RoundingMode::class.'::Unnecessary') ? 'Unnecessary' : 'UNNECESSARY';

        return constant(RoundingMode::class.'::'.$name);
    }
}
