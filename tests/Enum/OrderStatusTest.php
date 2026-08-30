<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{OrderStatus, OrderStatus, bool}>
     */
    public static function transitions(): iterable
    {
        yield 'pending to confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed, true];
        yield 'pending to cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled, true];
        yield 'pending straight to shipped' => [OrderStatus::Pending, OrderStatus::Shipped, false];
        yield 'confirmed to shipped' => [OrderStatus::Confirmed, OrderStatus::Shipped, true];
        yield 'confirmed to cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled, true];
        yield 'confirmed back to pending' => [OrderStatus::Confirmed, OrderStatus::Pending, false];
        yield 'shipped cannot be cancelled' => [OrderStatus::Shipped, OrderStatus::Cancelled, false];
        yield 'cancelled cannot be revived' => [OrderStatus::Cancelled, OrderStatus::Confirmed, false];
        yield 'no status transitions to itself' => [OrderStatus::Pending, OrderStatus::Pending, false];
    }

    #[DataProvider('transitions')]
    public function testTransitionRules(OrderStatus $from, OrderStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }
}
