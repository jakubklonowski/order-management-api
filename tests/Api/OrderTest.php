<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Inventory;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\UserRole;
use App\Tests\ApiTestCase;

final class OrderTest extends ApiTestCase
{
    private User $customer;
    private string $customerToken;
    private string $adminToken;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->createUser('customer@domain.pl');
        $this->customerToken = $this->tokenFor($this->customer);
        $this->adminToken = $this->tokenForNewUser('admin@domain.pl', UserRole::Admin);

        $this->category = new Category('Tools');
        $this->em->persist($this->category);
        $this->em->flush();
    }

    private function product(string $sku, string $price = '19.99', ?int $stock = 10): Product
    {
        $product = new Product('Hammer', $price, $sku, $this->category);
        $this->em->persist($product);

        // null stock means the product was never stocked at all
        if (null !== $stock) {
            $this->em->persist(new Inventory($product, $stock, 2));
        }

        $this->em->flush();

        return $product;
    }

    /**
     * @param array<int, array{productId: int|null, quantity: int|null}> $lines
     */
    private static function payload(array $lines): string
    {
        return json_encode(['items' => $lines], \JSON_THROW_ON_ERROR);
    }

    private function availableFor(int $productId): int
    {
        $this->em->clear();
        $inventory = $this->em->getRepository(Inventory::class)->findOneBy(['product' => $productId]);

        return $inventory->getAvailableQuantity();
    }

    private function countOrders(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(Order::class)->findAll());
    }

    public function testPlacingRequiresAuthentication(): void
    {
        $product = $this->product('SKU-1');

        $this->request('POST', '/api/orders', self::payload([['productId' => $product->getId(), 'quantity' => 1]]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testCustomerPlacesOrder(): void
    {
        $product = $this->product('SKU-1', '19.99', 10);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 2],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseBody();

        self::assertSame(OrderStatus::Pending->value, $body['status']);
        self::assertSame('39.98', $body['totalPrice']);
        self::assertCount(1, $body['items']);
        self::assertSame($product->getId(), $body['items'][0]['productId']);
        self::assertSame(2, $body['items'][0]['quantity']);
        self::assertSame('19.99', $body['items'][0]['unitPrice']);
    }

    public function testPlacingReservesStock(): void
    {
        $product = $this->product('SKU-1', '19.99', 10);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 3],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(201);
        // quantity is untouched, only the reserved number changed
        self::assertSame(7, $this->availableFor($product->getId()));
    }

    public function testTotalKeepsTwoDecimalPlaces(): void
    {
        // bcmul without an explicit scale truncates to an integer and returns "59"
        $product = $this->product('SKU-1', '19.99', 10);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 3],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('59.97', $this->responseBody()['totalPrice']);
    }

    public function testTotalSumsEveryLine(): void
    {
        $hammer = $this->product('SKU-1', '19.99', 10);
        $saw = $this->product('SKU-2', '5.01', 10);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $hammer->getId(), 'quantity' => 2],
            ['productId' => $saw->getId(), 'quantity' => 1],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('44.99', $this->responseBody()['totalPrice']);
    }

    public function testOrderingMoreThanAvailableIsRejected(): void
    {
        $product = $this->product('SKU-1', '19.99', 5);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 6],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('items', $this->responseBody()['errors']);
        self::assertSame(5, $this->availableFor($product->getId()));
        self::assertSame(0, $this->countOrders());
    }

    public function testAFailedLineRollsBackTheWholeOrder(): void
    {
        $plenty = $this->product('SKU-1', '19.99', 10);
        $scarce = $this->product('SKU-2', '19.99', 1);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $plenty->getId(), 'quantity' => 2],
            ['productId' => $scarce->getId(), 'quantity' => 5],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(409);
        // first line reserved successfully before the second failed
        self::assertSame(10, $this->availableFor($plenty->getId()));
        self::assertSame(1, $this->availableFor($scarce->getId()));
        self::assertSame(0, $this->countOrders());
    }

    public function testOrderingAnUnstockedProductIsRejected(): void
    {
        $product = $this->product('SKU-1', '19.99', stock: null);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 1],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->countOrders());
    }

    public function testRepeatedProductLinesAreMerged(): void
    {
        $product = $this->product('SKU-1', '19.99', 10);

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 2],
            ['productId' => $product->getId(), 'quantity' => 3],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->responseBody()['items']);
        self::assertSame(5, $this->responseBody()['items'][0]['quantity']);
        self::assertSame(5, $this->availableFor($product->getId()));
    }

    public function testOrderRejectsUnknownProduct(): void
    {
        $this->request('POST', '/api/orders', self::payload([
            ['productId' => 999999, 'quantity' => 1],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countOrders());
    }

    public function testOrderRejectsEmptyItems(): void
    {
        $this->request('POST', '/api/orders', self::payload([]), $this->customerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('items', $this->responseBody()['errors']);
    }

    public function testOrderRejectsNonPositiveQuantity(): void
    {
        $product = $this->product('SKU-1');

        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 0],
        ]), $this->customerToken);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countOrders());
    }

    public function testListingShowsOnlyTheCallersOrders(): void
    {
        $product = $this->product('SKU-1');
        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 1],
        ]), $this->customerToken);
        self::assertResponseStatusCodeSame(201);

        $otherToken = $this->tokenForNewUser('other@domain.pl');
        $this->request('GET', '/api/orders', token: $otherToken);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseBody());
    }

    public function testAdminListingShowsEveryOrder(): void
    {
        $product = $this->product('SKU-1');
        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 1],
        ]), $this->customerToken);
        self::assertResponseStatusCodeSame(201);

        $this->request('GET', '/api/orders', token: $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->responseBody());
    }

    public function testListingOmitsItems(): void
    {
        $product = $this->product('SKU-1');
        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 1],
        ]), $this->customerToken);

        $this->request('GET', '/api/orders', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            ['id', 'userId', 'status', 'totalPrice', 'createdAt'],
            array_keys($this->responseBody()[0]),
        );
    }

    private function placeOrder(): int
    {
        $product = $this->product('SKU-1');
        $this->request('POST', '/api/orders', self::payload([
            ['productId' => $product->getId(), 'quantity' => 1],
        ]), $this->customerToken);
        self::assertResponseStatusCodeSame(201);

        return $this->responseBody()['id'];
    }

    public function testCustomerCannotViewAnotherUsersOrder(): void
    {
        $orderId = $this->placeOrder();

        $this->request('GET', '/api/orders/'.$orderId, token: $this->tokenForNewUser('other@domain.pl'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanViewAnyOrder(): void
    {
        $orderId = $this->placeOrder();

        $this->request('GET', '/api/orders/'.$orderId, token: $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertSame($this->customer->getId(), $this->responseBody()['userId']);
    }

    public function testShowRejectsUnknownOrder(): void
    {
        $this->request('GET', '/api/orders/999999', token: $this->customerToken);

        self::assertResponseStatusCodeSame(404);
    }

    public function testHistoryStartsWithPending(): void
    {
        $orderId = $this->placeOrder();

        $this->request('GET', '/api/orders/'.$orderId.'/history', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();

        self::assertCount(1, $body);
        self::assertSame(OrderStatus::Pending->value, $body[0]['status']);
        self::assertSame($this->customer->getId(), $body[0]['changedById']);
    }

    public function testHistoryIsClosedToOtherCustomers(): void
    {
        $orderId = $this->placeOrder();

        $this->request('GET', '/api/orders/'.$orderId.'/history', token: $this->tokenForNewUser('other@domain.pl'));

        self::assertResponseStatusCodeSame(403);
    }
}
