<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Inventory;
use App\Entity\Order;
use App\Entity\Product;
use App\Repository\InventoryRepository;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Proves the stock row is genuinely locked while an order is being placed.
 *
 * A second connection holds SELECT ... FOR UPDATE on the row, so order
 * request can only get through if it never asked for the lock at all.
 */
final class OrderConcurrencyTest extends ApiTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    // InnoDB polls for lock waits rather than timing out to the millisecond
    // so it gives up slightly under the configured value
    // unblocked order takes milliseconds so anything above a second proves it queued
    private const MIN_BLOCKED_SECONDS = 1.0;

    private string $customerToken;
    private Product $product;
    private ?Connection $blocker = null;

    protected function setUp(): void
    {
        parent::setUp();

        // SET SESSION below applies to one database connection
        // second request in this test would restart the kernel, open a
        // fresh connection, and wait the default 50 seconds instead of 2
        $this->client->disableReboot();

        $this->customerToken = $this->tokenForNewUser('customer@domain.pl');

        $category = new Category('Tools');
        $this->em->persist($category);

        $this->product = new Product('Hammer', '19.99', 'SKU-1', $category);
        $this->em->persist($this->product);
        $this->em->persist(new Inventory($this->product, 1, 0));
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'SET SESSION innodb_lock_wait_timeout = '.self::LOCK_TIMEOUT_SECONDS
        );
    }

    protected function tearDown(): void
    {
        // release the row before the next test tries to empty the table
        if (null !== $this->blocker) {
            $this->blocker->rollBack();
            $this->blocker->close();
            $this->blocker = null;
        }

        parent::tearDown();
    }

    private function holdStockRowLock(): void
    {
        $this->blocker = DriverManager::getConnection($this->em->getConnection()->getParams());
        $this->blocker->beginTransaction();
        $this->blocker->executeQuery(
            'SELECT id FROM inventory WHERE product_id = ? FOR UPDATE',
            [$this->product->getId()]
        );
    }

    private function orderOne(): void
    {
        $this->request('POST', '/api/orders', json_encode([
            'items' => [['productId' => $this->product->getId(), 'quantity' => 1]],
        ], \JSON_THROW_ON_ERROR), $this->customerToken);
    }

    // test checks if FOR UPDATE clause has actually been used; the two test below check
    // against other problems while staying green without the lock clause in place
    public function testStockLookupSelectsForUpdate(): void
    {
        $queries = static::getContainer()->get('doctrine.debug_data_holder');
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $queries->reset();

        static::getContainer()->get(InventoryRepository::class)
            ->findOneByProductIdForUpdate($this->product->getId());

        $connection->rollBack();

        self::assertStringContainsString('FOR UPDATE', $queries->getData()['default'][0]['sql'] ?? '');
    }

    // without this the blocked case below proves nothing
    public function testOrderSucceedsWhenNobodyHoldsTheRow(): void
    {
        $this->orderOne();

        self::assertResponseStatusCodeSame(201);
    }

    public function testOrderWaitsForAConcurrentLockAndIsRefused(): void
    {
        $this->holdStockRowLock();

        $startedAt = microtime(true);
        $this->orderOne();
        $waited = microtime(true) - $startedAt;

        self::assertResponseStatusCodeSame(409);
        // the lock timeout path, not the out-of-stock one
        self::assertStringContainsString('Try again', $this->responseBody()['errors']['items'][0]);
        // it queued behind the lock rather than reading around it
        self::assertGreaterThan(self::MIN_BLOCKED_SECONDS, $waited);
    }

    public function testRefusedOrderLeavesNothingBehind(): void
    {
        $this->holdStockRowLock();

        $this->orderOne();
        self::assertResponseStatusCodeSame(409);

        $this->blocker->rollBack();
        $this->blocker->close();
        $this->blocker = null;

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Order::class)->findAll());
        self::assertSame(
            1,
            $this->em->getRepository(Inventory::class)
                ->findOneBy(['product' => $this->product->getId()])
                ->getAvailableQuantity()
        );
    }
}
