<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PromoCode;
use App\Enum\PromoCodeType;
use PHPUnit\Framework\TestCase;

final class PromoCodeTest extends TestCase
{
    public function testCodeIsNormalisedToUppercase(): void
    {
        $promo = new PromoCode('save10', PromoCodeType::Percentage, '10.00', 5);

        self::assertSame('SAVE10', $promo->getCode());
    }

    public function testNewCodeIsValid(): void
    {
        $promo = new PromoCode('SAVE10', PromoCodeType::Percentage, '10.00', 5);

        self::assertTrue($promo->isValid());
        self::assertSame(0, $promo->getUsedCount());
    }

    public function testCodeIsInvalidOnceUsesAreExhausted(): void
    {
        $promo = new PromoCode('SAVE10', PromoCodeType::Percentage, '10.00', 2);

        $promo->incrementUsedCount();
        self::assertTrue($promo->isValid());

        $promo->incrementUsedCount();
        self::assertFalse($promo->isValid());
    }

    public function testExpiredCodeIsInvalid(): void
    {
        $promo = new PromoCode('SAVE10', PromoCodeType::Percentage, '10.00', 5, new \DateTimeImmutable('-1 day'));

        self::assertFalse($promo->isValid());
    }

    public function testNotExpiredCodeIsValid(): void
    {
        $promo = new PromoCode('SAVE10', PromoCodeType::Percentage, '10.00', 5, new \DateTimeImmutable('+1 day'));

        self::assertTrue($promo->isValid());
    }

    public function testCodeWithoutExpiryNeverExpires(): void
    {
        $promo = new PromoCode('SAVE10', PromoCodeType::Percentage, '10.00', 5, null);

        self::assertTrue($promo->isValid());
    }
}
