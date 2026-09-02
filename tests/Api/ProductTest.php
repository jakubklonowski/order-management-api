<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Inventory;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\UserRole;
use App\Tests\ApiTestCase;

final class ProductTest extends ApiTestCase
{
    private string $adminToken;
    private string $customerToken;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenForNewUser('admin@domain.pl', UserRole::Admin);
        $this->customerToken = $this->tokenForNewUser('customer@domain.pl');
        $this->category = $this->category('Tools');
    }

    private function category(string $name): Category
    {
        $category = new Category($name);

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function product(string $name, string $sku, ?Category $category = null, string $price = '19.99'): Product
    {
        $product = new Product($name, $price, $sku, $category ?? $this->category);

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function stock(Product $product, int $quantity, int $reserved = 0): void
    {
        $inventory = new Inventory($product, $quantity, 5);
        $inventory->reserve($reserved);

        $this->em->persist($inventory);
        $this->em->flush();
    }

    private function payload(
        string $name = 'Hammer',
        mixed $price = '19.99',
        string $sku = 'SKU-1',
        ?int $categoryId = null,
        ?string $description = null,
    ): string {
        return json_encode([
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'sku' => $sku,
            'categoryId' => $categoryId ?? $this->category->getId(),
        ], \JSON_THROW_ON_ERROR);
    }

    private function countProducts(): int
    {
        return \count($this->em->getRepository(Product::class)->findAll());
    }

    // clears cache so test is forced to assert data against database
    private function reload(int $id): Product
    {
        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($id);

        self::assertNotNull($product);

        return $product;
    }

    public function testListingRequiresAuthentication(): void
    {
        $this->request('GET', '/api/products');

        self::assertResponseStatusCodeSame(401);
    }

    public function testListingReportsPageMetadata(): void
    {
        $this->product('Hammer', 'SKU-1');
        $this->product('Saw', 'SKU-2');
        $this->product('Drill', 'SKU-3');

        $this->request('GET', '/api/products?limit=2', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();

        self::assertCount(2, $body['items']);
        self::assertSame(1, $body['page']);
        self::assertSame(2, $body['limit']);
        // total counts every match, not just the page
        self::assertSame(3, $body['total']);
    }

    public function testListingReturnsTheRemainderOnTheLastPage(): void
    {
        $this->product('Hammer', 'SKU-1');
        $this->product('Saw', 'SKU-2');
        $this->product('Drill', 'SKU-3');

        $this->request('GET', '/api/products?page=2&limit=2', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->responseBody()['items']);
    }

    public function testListingOmitsTheDescription(): void
    {
        $this->product('Hammer', 'SKU-1');

        $this->request('GET', '/api/products', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            ['id', 'name', 'price', 'sku', 'categoryId', 'availableQuantity'],
            array_keys($this->responseBody()['items'][0]),
        );
    }

    public function testListingFiltersByCategory(): void
    {
        $adhesives = $this->category('Adhesives');
        $this->product('Hammer', 'SKU-1');
        $this->product('Glue', 'SKU-2', $adhesives);

        $this->request('GET', '/api/products?categoryId='.$adhesives->getId(), token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertSame(['Glue'], array_column($this->responseBody()['items'], 'name'));
    }

    public function testListingFiltersByName(): void
    {
        $this->product('Claw Hammer', 'SKU-1');
        $this->product('Hand Saw', 'SKU-2');

        $this->request('GET', '/api/products?search=hammer', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertSame(['Claw Hammer'], array_column($this->responseBody()['items'], 'name'));
    }

    public function testListingAcceptsAnUnknownCategoryFilter(): void
    {
        $this->product('Hammer', 'SKU-1');

        $this->request('GET', '/api/products?categoryId=999999', token: $this->customerToken);

        // a filter that matches nothing is an empty page, not a rejected request
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseBody()['items']);
        self::assertSame(0, $this->responseBody()['total']);
    }

    public function testListingRejectsPageBelowOne(): void
    {
        // MapQueryString would answer 404 here if the status code was left at its default
        $this->request('GET', '/api/products?page=0', token: $this->customerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('page', $this->responseBody()['errors']);
    }

    public function testListingRejectsLimitAboveTheMaximum(): void
    {
        $this->request('GET', '/api/products?limit=101', token: $this->customerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('limit', $this->responseBody()['errors']);
    }

    public function testListingRejectsNonNumericPage(): void
    {
        $this->request('GET', '/api/products?page=first', token: $this->customerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('page', $this->responseBody()['errors']);
    }

    public function testListingLoadsEveryStockLevelInOneQuery(): void
    {
        foreach (['SKU-1', 'SKU-2', 'SKU-3'] as $sku) {
            $this->stock($this->product('Item '.$sku, $sku), 10);
        }

        // forces the reads to hit the database, otherwise hydration is served from
        // objects this test just created and the count proves nothing
        $this->em->clear();

        $queries = static::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();

        $this->em->getRepository(Product::class)->paginate(1, 20, null, null);

        $log = $queries->getData()['default'] ?? []; // doctrine keys the log by connection name

        // 2 = one count and one fetch
        self::assertCount(2, $log);
    }

    public function testShowReturnsTheDetailFields(): void
    {
        $product = $this->product('Hammer', 'SKU-1');
        $product->setDescription('A claw hammer.');
        $this->em->flush();

        $this->request('GET', '/api/products/'.$product->getId(), token: $this->customerToken);

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();

        self::assertEqualsCanonicalizing(
            ['id', 'name', 'description', 'price', 'sku', 'createdAt', 'categoryId', 'availableQuantity'],
            array_keys($body),
        );
        self::assertSame('A claw hammer.', $body['description']);
        self::assertSame($this->category->getId(), $body['categoryId']);
    }

    public function testShowRejectsUnknownId(): void
    {
        $this->request('GET', '/api/products/999999', token: $this->customerToken);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAvailableQuantityLeavesOutReservedStock(): void
    {
        $product = $this->product('Hammer', 'SKU-1');
        $this->stock($product, 10, reserved: 4);

        $this->request('GET', '/api/products/'.$product->getId(), token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertSame(6, $this->responseBody()['availableQuantity']);
    }

    public function testAvailableQuantityIsZeroWithoutAnInventoryRow(): void
    {
        $product = $this->product('Hammer', 'SKU-1');

        $this->request('GET', '/api/products/'.$product->getId(), token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->responseBody()['availableQuantity']);
    }

    public function testCustomerCannotCreate(): void
    {
        $this->request('POST', '/api/products', $this->payload(), $this->customerToken);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countProducts());
    }

    public function testAdminCreatesProduct(): void
    {
        $this->request('POST', '/api/products', $this->payload(description: 'A claw hammer.'), $this->adminToken);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Hammer', $this->responseBody()['name']);

        $stored = $this->reload($this->responseBody()['id']);
        self::assertSame('19.99', $stored->getPrice());
        self::assertSame('SKU-1', $stored->getSku());
        self::assertSame('A claw hammer.', $stored->getDescription());
        self::assertSame($this->category->getId(), $stored->getCategory()->getId());
    }

    public function testCreateRejectsPriceSentAsANumber(): void
    {
        // price has to be string to preserve its precision until it can be calculated or stored properly
        $this->request('POST', '/api/products', $this->payload(price: 19.99), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('price', $this->responseBody()['errors']);
        self::assertSame(0, $this->countProducts());
    }

    public function testCreateRejectsMoreThanTwoDecimalPlaces(): void
    {
        $this->request('POST', '/api/products', $this->payload(price: '19.999'), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('price', $this->responseBody()['errors']);
        self::assertSame(0, $this->countProducts());
    }

    public function testCreateRejectsPriceWiderThanTheColumn(): void
    {
        // DECIMAL(10,2) leaves eight digits before the point
        $this->request('POST', '/api/products', $this->payload(price: '123456789.00'), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('price', $this->responseBody()['errors']);
        self::assertSame(0, $this->countProducts());
    }

    public function testCreateRejectsBlankName(): void
    {
        $this->request('POST', '/api/products', $this->payload(name: '   '), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('name', $this->responseBody()['errors']);
        self::assertSame(0, $this->countProducts());
    }

    public function testCreateRejectsUnknownCategory(): void
    {
        $this->request('POST', '/api/products', $this->payload(categoryId: 999999), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('categoryId', $this->responseBody()['errors']);
        self::assertSame(0, $this->countProducts());
    }

    public function testCreateRejectsMissingCategory(): void
    {
        $this->request('POST', '/api/products', json_encode([
            'name' => 'Hammer',
            'price' => '19.99',
            'sku' => 'SKU-1',
        ], \JSON_THROW_ON_ERROR), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('categoryId', $this->responseBody()['errors']);
    }

    public function testCreateRejectsDuplicateSku(): void
    {
        $this->product('Hammer', 'SKU-1');

        $this->request('POST', '/api/products', $this->payload(name: 'Other', sku: 'SKU-1'), $this->adminToken);

        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('sku', $this->responseBody()['errors']);
        self::assertSame(1, $this->countProducts());
    }

    public function testUpdateReplacesEveryField(): void
    {
        $adhesives = $this->category('Adhesives');
        $productId = $this->product('Hamer', 'SKU-1')->getId();

        $this->request('PUT', '/api/products/'.$productId, $this->payload(
            name: 'Hammer',
            price: '24.50',
            sku: 'SKU-9',
            categoryId: $adhesives->getId(),
            description: 'Reworked.',
        ), $this->adminToken);

        self::assertResponseIsSuccessful();

        $updated = $this->reload($productId);
        self::assertSame('Hammer', $updated->getName());
        self::assertSame('24.50', $updated->getPrice());
        self::assertSame('SKU-9', $updated->getSku());
        self::assertSame('Reworked.', $updated->getDescription());
        self::assertSame($adhesives->getId(), $updated->getCategory()->getId());
    }

    public function testUpdateAllowsProductToKeepItsOwnSku(): void
    {
        $productId = $this->product('Hamer', 'SKU-1')->getId();

        $this->request('PUT', '/api/products/'.$productId, $this->payload(sku: 'SKU-1'), $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertSame('SKU-1', $this->reload($productId)->getSku());
    }

    public function testUpdateRejectsAnotherProductsSku(): void
    {
        $this->product('Hammer', 'SKU-1');
        $productId = $this->product('Saw', 'SKU-2')->getId();

        $this->request('PUT', '/api/products/'.$productId, $this->payload(sku: 'SKU-1'), $this->adminToken);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('SKU-2', $this->reload($productId)->getSku());
    }

    public function testUpdateClearsAnOmittedDescription(): void
    {
        $product = $this->product('Hammer', 'SKU-1');
        $product->setDescription('A claw hammer.');
        $this->em->flush();

        $this->request('PUT', '/api/products/'.$product->getId(), $this->payload(), $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertNull($this->reload($product->getId())->getDescription());
    }

    public function testCustomerCannotDelete(): void
    {
        $product = $this->product('Hammer', 'SKU-1');

        $this->request('DELETE', '/api/products/'.$product->getId(), token: $this->customerToken);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->countProducts());
    }

    public function testAdminDeletesProductAndItsInventory(): void
    {
        $product = $this->product('Hammer', 'SKU-1');
        $this->stock($product, 10);

        $this->request('DELETE', '/api/products/'.$product->getId(), token: $this->adminToken);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->countProducts());
        self::assertCount(0, $this->em->getRepository(Inventory::class)->findAll());
    }

    public function testDeleteFailsOnProductThatAppearsOnOrder(): void
    {
        $product = $this->product('Hammer', 'SKU-1');

        $order = new Order($this->createUser('buyer@domain.pl'));
        $order->addItem($product, 1, '19.99');
        $this->em->persist($order);
        $this->em->flush();

        $this->request('DELETE', '/api/products/'.$product->getId(), token: $this->adminToken);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->countProducts());
    }
}
