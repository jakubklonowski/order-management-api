<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Inventory;
use App\Entity\Product;
use App\Enum\UserRole;
use App\Tests\ApiTestCase;

final class InventoryTest extends ApiTestCase
{
    private string $adminToken;
    private string $customerToken;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenForNewUser('admin@domain.pl', UserRole::Admin);
        $this->customerToken = $this->tokenForNewUser('customer@domain.pl');

        $this->category = new Category('Tools');
        $this->em->persist($this->category);
        $this->em->flush();
    }

    private function product(string $sku = 'SKU-1'): Product
    {
        $product = new Product('Hammer', '19.99', $sku, $this->category);

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function stocked(int $quantity = 10, int $reserved = 0, int $threshold = 3): Product
    {
        $product = $this->product();
        $inventory = new Inventory($product, $quantity, $threshold);
        $inventory->reserve($reserved);

        $this->em->persist($inventory);
        $this->em->flush();

        return $product;
    }

    private static function payload(?int $quantity = 25, ?int $lowStockThreshold = 5): string
    {
        return json_encode([
            'quantity' => $quantity,
            'lowStockThreshold' => $lowStockThreshold,
        ], \JSON_THROW_ON_ERROR);
    }

    // clears cache so test is forced to assert data against database
    private function reload(int $productId): ?Inventory
    {
        $this->em->clear();

        return $this->em->getRepository(Inventory::class)->findOneBy(['product' => $productId]);
    }

    public function testReadingRequiresAuthentication(): void
    {
        $this->request('GET', '/api/inventory/'.$this->stocked()->getId());

        self::assertResponseStatusCodeSame(401);
    }

    public function testCustomerCannotReadStock(): void
    {
        $this->request('GET', '/api/inventory/'.$this->stocked()->getId(), token: $this->customerToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReadsStockRecord(): void
    {
        $product = $this->stocked(quantity: 10, reserved: 4, threshold: 3);

        $this->request('GET', '/api/inventory/'.$product->getId(), token: $this->adminToken);

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();

        self::assertEqualsCanonicalizing(
            ['productId', 'quantity', 'reservedQuantity', 'lowStockThreshold', 'availableQuantity', 'lowStock'],
            array_keys($body),
        );
        self::assertSame($product->getId(), $body['productId']);
        self::assertSame(10, $body['quantity']);
        self::assertSame(4, $body['reservedQuantity']);
        self::assertSame(6, $body['availableQuantity']);
        self::assertFalse($body['lowStock']);
    }

    public function testLowStockComparesAgainstAvailableNotQuantity(): void
    {
        // 2 left unreserved with threshold 3 should return lowStock = true
        $product = $this->stocked(quantity: 10, reserved: 8, threshold: 3);

        $this->request('GET', '/api/inventory/'.$product->getId(), token: $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->responseBody()['lowStock']);
    }

    public function testReadRejectsProductWithoutStockRecord(): void
    {
        $this->request('GET', '/api/inventory/'.$this->product()->getId(), token: $this->adminToken);

        self::assertResponseStatusCodeSame(404);
    }

    public function testReadRejectsUnknownProduct(): void
    {
        $this->request('GET', '/api/inventory/999999', token: $this->adminToken);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCustomerCannotUpdateStock(): void
    {
        $product = $this->stocked(quantity: 10);

        $this->request('PUT', '/api/inventory/'.$product->getId(), self::payload(), $this->customerToken);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(10, $this->reload($product->getId())->getQuantity());
    }

    public function testUpdateCreatesTheMissingStockRecord(): void
    {
        $product = $this->product();

        $this->request('PUT', '/api/inventory/'.$product->getId(), self::payload(25, 5), $this->adminToken);

        // PUT created previously absent stock row
        self::assertResponseStatusCodeSame(201);
        self::assertSame(25, $this->responseBody()['quantity']);

        $stored = $this->reload($product->getId());
        self::assertNotNull($stored);
        self::assertSame(25, $stored->getQuantity());
        self::assertSame(5, $stored->getLowStockThreshold());
        self::assertSame(0, $stored->getReservedQuantity());
    }

    public function testUpdateReplacesAnExistingStockRecord(): void
    {
        $product = $this->stocked(quantity: 10, threshold: 3);

        $this->request('PUT', '/api/inventory/'.$product->getId(), self::payload(25, 5), $this->adminToken);

        self::assertResponseStatusCodeSame(200);

        $stored = $this->reload($product->getId());
        self::assertSame(25, $stored->getQuantity());
        self::assertSame(5, $stored->getLowStockThreshold());
    }

    public function testUpdateLeavesReservedQuantityAlone(): void
    {
        $product = $this->stocked(quantity: 10, reserved: 4);

        // reservedQuantity is not for Inventory API to modify (although this is an Inventory column)
        $this->request('PUT', '/api/inventory/'.$product->getId(), json_encode([
            'quantity' => 25,
            'lowStockThreshold' => 5,
            'reservedQuantity' => 0,
        ], \JSON_THROW_ON_ERROR), $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->reload($product->getId())->getReservedQuantity());
    }

    public function testUpdateRejectsQuantityBelowReservedStock(): void
    {
        $product = $this->stocked(quantity: 10, reserved: 4);

        $this->request('PUT', '/api/inventory/'.$product->getId(), self::payload(2, 5), $this->adminToken);

        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('quantity', $this->responseBody()['errors']);
        self::assertSame(10, $this->reload($product->getId())->getQuantity());
    }

    public function testUpdateAcceptsQuantityEqualToReservedStock(): void
    {
        $product = $this->stocked(quantity: 10, reserved: 4);

        $this->request('PUT', '/api/inventory/'.$product->getId(), self::payload(4, 5), $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->responseBody()['availableQuantity']);
    }

    public function testUpdateRejectsNegativeQuantity(): void
    {
        $product = $this->stocked(quantity: 10);

        $this->request('PUT', '/api/inventory/'.$product->getId(), self::payload(-1, 5), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('quantity', $this->responseBody()['errors']);
        self::assertSame(10, $this->reload($product->getId())->getQuantity());
    }

    public function testUpdateRejectsMissingFields(): void
    {
        $product = $this->stocked();

        $this->request('PUT', '/api/inventory/'.$product->getId(), '{}', $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(
            ['quantity', 'lowStockThreshold'],
            array_keys($this->responseBody()['errors']),
        );
    }

    public function testUpdateRejectsUnknownProduct(): void
    {
        $this->request('PUT', '/api/inventory/999999', self::payload(), $this->adminToken);

        self::assertResponseStatusCodeSame(404);
    }
}
