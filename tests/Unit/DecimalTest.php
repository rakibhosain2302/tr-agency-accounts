<?php

namespace Nit\AgraniAccounts\Tests\Unit;

use Nit\AgraniAccounts\Support\Decimal;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_exact_keeps_the_scale_and_refuses_rounding(): void
    {
        $this->assertSame('10.50', Decimal::exact('10.5'));
        $this->assertSame('0.00', Decimal::exact(null));
        $this->assertSame('1.10000000', Decimal::exact('1.1', 8));
        $this->assertNull(Decimal::exact('10.005'));
        $this->assertNull(Decimal::exact('ten'));
    }

    public function test_sum_is_exact_where_floats_drift(): void
    {
        $this->assertSame('0.30', Decimal::sum(['0.10', '0.20']));
        $this->assertSame('1000000000000000.01', Decimal::sum(['999999999999999.99', '0.02']));
    }

    public function test_sign_checks(): void
    {
        $this->assertTrue(Decimal::isPositive('0.01'));
        $this->assertFalse(Decimal::isPositive('0.00'));
        $this->assertTrue(Decimal::isNegative('-0.01'));
    }
}
