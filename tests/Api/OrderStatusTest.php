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

final class OrderStatusTest extends ApiTestCase
{
    private User $customer;
    private string $customerToken;
    private string $adminToken;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->createUser('customer@domain.pl');
        $this->customerToken = $this->tokenFor($this->customer);
        $this->adminToken = $this->tokenForNewUser('admin@domain.pl', UserRole::Admin);

        $category = new Category('Tools');
        $this->em->persist($category);

        $this->product = new Product('Hammer', '19.99', 'SKU-1', $category);
        $this->em->persist($this->product);
        $this->em->persist(new Inventory($this->product, 10, 2));
        $this->em->flush();
    }

    // returns order id
    private function placeOrder(int $quantity = 3): int
    {
        $this->request('POST', '/api/orders', json_encode([
            'items' => [['productId' => $this->product->getId(), 'quantity' => $quantity]],
        ], \JSON_THROW_ON_ERROR), $this->customerToken);

        self::assertResponseStatusCodeSame(201);

        return $this->responseBody()['id'];
    }

    private function setStatus(int $orderId, string $status): void
    {
        $this->request(
            'PUT',
            '/api/orders/'.$orderId.'/status',
            json_encode(['status' => $status], \JSON_THROW_ON_ERROR),
            $this->adminToken
        );
    }

    private function reloadStock(): Inventory
    {
        $this->em->clear();

        return $this->em->getRepository(Inventory::class)->findOneBy(['product' => $this->product->getId()]);
    }

    private function reloadOrder(int $orderId): Order
    {
        $this->em->clear();

        return $this->em->getRepository(Order::class)->find($orderId);
    }

    public function testCustomerCancelsOwnOrder(): void
    {
        $orderId = $this->placeOrder(3);

        $this->request('POST', '/api/orders/'.$orderId.'/cancel', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertSame(OrderStatus::Cancelled->value, $this->responseBody()['status']);
        self::assertSame(OrderStatus::Cancelled, $this->reloadOrder($orderId)->getStatus());
    }

    public function testCancellingReleasesTheReservation(): void
    {
        $orderId = $this->placeOrder(3);
        self::assertSame(7, $this->reloadStock()->getAvailableQuantity());

        $this->request('POST', '/api/orders/'.$orderId.'/cancel', token: $this->customerToken);
        self::assertResponseIsSuccessful();

        $stock = $this->reloadStock();
        // nothing shipped, stock unchanged
        self::assertSame(10, $stock->getAvailableQuantity());
        self::assertSame(10, $stock->getQuantity());
        self::assertSame(0, $stock->getReservedQuantity());
    }

    public function testCancellingIsRecordedInHistory(): void
    {
        $orderId = $this->placeOrder();

        $this->request('POST', '/api/orders/'.$orderId.'/cancel', token: $this->customerToken);
        self::assertResponseIsSuccessful();

        $this->request('GET', '/api/orders/'.$orderId.'/history', token: $this->customerToken);
        $history = $this->responseBody();

        self::assertCount(2, $history);
        self::assertSame(OrderStatus::Pending->value, $history[0]['status']);
        self::assertSame(OrderStatus::Cancelled->value, $history[1]['status']);
        self::assertSame($this->customer->getId(), $history[1]['changedById']);
    }

    public function testCustomerCannotCancelAnotherCustomersOrder(): void
    {
        $orderId = $this->placeOrder();

        $this->request(
            'POST',
            '/api/orders/'.$orderId.'/cancel',
            token: $this->tokenForNewUser('other@domain.pl')
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::Pending, $this->reloadOrder($orderId)->getStatus());
    }

    public function testCancellingTwiceIsRejected(): void
    {
        $orderId = $this->placeOrder(3);

        $this->request('POST', '/api/orders/'.$orderId.'/cancel', token: $this->customerToken);
        self::assertResponseIsSuccessful();

        $this->request('POST', '/api/orders/'.$orderId.'/cancel', token: $this->customerToken);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(10, $this->reloadStock()->getAvailableQuantity());
    }

    public function testConfirmingAdvancesStatusWithoutMovingStock(): void
    {
        $orderId = $this->placeOrder(3);

        $this->setStatus($orderId, OrderStatus::Confirmed->value);

        self::assertResponseIsSuccessful();
        self::assertSame(OrderStatus::Confirmed, $this->reloadOrder($orderId)->getStatus());
        self::assertSame(7, $this->reloadStock()->getAvailableQuantity());
    }

    public function testShippingConsumesStock(): void
    {
        $orderId = $this->placeOrder(3);
        $this->setStatus($orderId, OrderStatus::Confirmed->value);
        self::assertResponseIsSuccessful();

        $this->setStatus($orderId, OrderStatus::Shipped->value);

        self::assertResponseIsSuccessful();
        $stock = $this->reloadStock();
        self::assertSame(7, $stock->getQuantity());
        self::assertSame(0, $stock->getReservedQuantity());
        self::assertSame(7, $stock->getAvailableQuantity());
    }

    public function testStatusCannotSkipConfirmation(): void
    {
        $orderId = $this->placeOrder(3);

        $this->setStatus($orderId, OrderStatus::Shipped->value);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(OrderStatus::Pending, $this->reloadOrder($orderId)->getStatus());
        self::assertSame(10, $this->reloadStock()->getQuantity());
    }

    public function testShippedOrderCannotBeCancelled(): void
    {
        $orderId = $this->placeOrder(3);
        $this->setStatus($orderId, OrderStatus::Confirmed->value);
        $this->setStatus($orderId, OrderStatus::Shipped->value);
        self::assertResponseIsSuccessful();

        $this->request('POST', '/api/orders/'.$orderId.'/cancel', token: $this->customerToken);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(7, $this->reloadStock()->getQuantity());
    }

    public function testCustomerCannotSetStatus(): void
    {
        $orderId = $this->placeOrder();

        $this->request(
            'PUT',
            '/api/orders/'.$orderId.'/status',
            json_encode(['status' => OrderStatus::Confirmed->value], \JSON_THROW_ON_ERROR),
            $this->customerToken
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::Pending, $this->reloadOrder($orderId)->getStatus());
    }

    public function testStatusRejectsUnknownValue(): void
    {
        $orderId = $this->placeOrder();

        $this->setStatus($orderId, 'teleported');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(OrderStatus::Pending, $this->reloadOrder($orderId)->getStatus());
    }

    public function testStatusRejectsMissingValue(): void
    {
        $orderId = $this->placeOrder();

        $this->request('PUT', '/api/orders/'.$orderId.'/status', '{}', $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('status', $this->responseBody()['errors']);
    }

    public function testCancellingRequiresAuthentication(): void
    {
        $orderId = $this->placeOrder();

        $this->request('POST', '/api/orders/'.$orderId.'/cancel');

        self::assertResponseStatusCodeSame(401);
    }
}
