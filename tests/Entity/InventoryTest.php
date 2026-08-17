<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Inventory;
use App\Entity\Product;
use PHPUnit\Framework\TestCase;

final class InventoryTest extends TestCase
{
    private function inventory(int $quantity = 10, int $threshold = 3): Inventory
    {
        return new Inventory(new Product('Hammer', '19.99', 'SKU-1', new Category('Tools')), $quantity, $threshold);
    }

    public function testAvailableSubtractsReserved(): void
    {
        $inventory = $this->inventory();
        self::assertSame(10, $inventory->getAvailableQuantity());

        $inventory->reserve(4);
        self::assertSame(6, $inventory->getAvailableQuantity());
        self::assertSame(4, $inventory->getReservedQuantity());
    }

    public function testReserveRejectsMoreThanAvailable(): void
    {
        $inventory = $this->inventory();

        try {
            $inventory->reserve(11);
            self::fail('Expected DomainException');
        } catch (\DomainException) {
            self::assertSame(0, $inventory->getReservedQuantity());
        }
    }

    public function testReserveAllowsExactlyAvailable(): void
    {
        $inventory = $this->inventory();
        $inventory->reserve(10);

        self::assertSame(0, $inventory->getAvailableQuantity());
    }

    public function testReleaseNeverGoesNegative(): void
    {
        $inventory = $this->inventory();
        $inventory->reserve(2);
        $inventory->release(5);

        self::assertSame(0, $inventory->getReservedQuantity());
    }

    public function testLowStockUsesAvailableNotRawQuantity(): void
    {
        $inventory = $this->inventory(quantity: 10, threshold: 3);
        self::assertFalse($inventory->isLowStock());

        $inventory->reserve(7);
        self::assertTrue($inventory->isLowStock());
    }

    public function testConstructorLinksProductBackToInventory(): void
    {
        $product = new Product('Hammer', '19.99', 'SKU-1', new Category('Tools'));
        $inventory = new Inventory($product, 5, 1);

        self::assertSame($inventory, $product->getInventory());
    }
}
