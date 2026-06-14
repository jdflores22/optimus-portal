<?php

namespace App\Tests\Unit\Util;

use App\Util\DecimalString;
use PHPUnit\Framework\TestCase;

class DecimalStringTest extends TestCase
{
    public function testRoundTripMoneyValues(): void
    {
        $stored = DecimalString::fromFloat(1500.5);
        $this->assertSame('1500.50', $stored);
        $this->assertSame(1500.5, DecimalString::toFloatOrZero($stored));
    }

    public function testExchangeRateScale(): void
    {
        $stored = DecimalString::fromFloat(56.7891, 4);
        $this->assertSame('56.7891', $stored);
        $this->assertSame(56.7891, DecimalString::toFloat($stored));
    }

    public function testNullableValues(): void
    {
        $this->assertNull(DecimalString::fromFloat(null));
        $this->assertNull(DecimalString::toFloat(null));
        $this->assertSame(0.0, DecimalString::toFloatOrZero(null));
    }
}
