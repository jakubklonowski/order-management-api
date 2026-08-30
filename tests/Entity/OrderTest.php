<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private function order(): Order
    {
        return new Order(new User('buyer@domain.pl'));
    }

    public function testNewOrderIsPendingAndAlreadyLogged(): void
    {
        $order = $this->order();

        self::assertSame(OrderStatus::Pending, $order->getStatus());
        self::assertCount(1, $order->getStatusHistory());
        self::assertSame(OrderStatus::Pending, $order->getStatusHistory()->first()->getStatus());
    }

    public function testChangeStatusRecordsWhatChangedAndWhoDidIt(): void
    {
        $order = $this->order();
        $admin = new User('admin@domain.pl', UserRole::Admin);

        $order->changeStatus(OrderStatus::Confirmed, $admin);

        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertCount(2, $order->getStatusHistory());

        $latest = $order->getStatusHistory()->last();
        self::assertSame(OrderStatus::Confirmed, $latest->getStatus());
        self::assertSame($admin, $latest->getChangedBy());
    }

    // this isn't duplication of OrderStatusTest because this one tests Order::changeStatus(), not enum
    public function testCannotShipAnUnconfirmedOrder(): void
    {
        $this->expectException(\DomainException::class);

        $this->order()->changeStatus(OrderStatus::Shipped, null);
    }

    public function testRejectedTransitionLeavesNoTrace(): void
    {
        $order = $this->order();

        try {
            $order->changeStatus(OrderStatus::Shipped, null);
        } catch (\DomainException) {
        }

        // rejected change must not land in the audit log
        self::assertSame(OrderStatus::Pending, $order->getStatus());
        self::assertCount(1, $order->getStatusHistory());
    }

    // tests if Doctrine links properly in both directions of ownership
    public function testAddedItemAppearsInTheOrdersCollection(): void
    {
        $order = $this->order();
        $product = new Product('Hammer', '19.99', 'SKU-1', new Category('Tools'));

        $item = $order->addItem($product, 2, '19.99');

        self::assertCount(1, $order->getItems());
        self::assertSame($item, $order->getItems()->first());
        self::assertSame($order, $item->getOrder());
        self::assertSame($product, $item->getProduct());
    }
}
