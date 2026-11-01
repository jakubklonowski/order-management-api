<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Category;
use App\Entity\Inventory;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\UserRole;
use App\Message\LowStockReached;
use App\Tests\ClearsDatabase;
use App\Tests\UsesMessenger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SendLowStockAlertTest extends KernelTestCase
{
    use ClearsDatabase;
    use UsesMessenger;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase($this->em);
    }

    private function user(string $email, UserRole $role): void
    {
        $this->em->persist(new User($email, $role));
        $this->em->flush();
    }

    private function stockedProduct(int $quantity, int $reserved, int $threshold): Product
    {
        $category = new Category('Tools');
        $product = new Product('Hammer', '19.99', 'SKU-1', $category);
        $inventory = new Inventory($product, $quantity, $threshold);
        $inventory->reserve($reserved);

        $this->em->persist($category);
        $this->em->persist($product);
        $this->em->persist($inventory);
        $this->em->flush();

        return $product;
    }

    private function returnLowStockAlertFor(Product $product): LowStockReached
    {
        $productId = $product->getId();
        self::assertNotNull($productId);

        return new LowStockReached($productId);
    }

    public function testMailsEveryAdminAndNoCustomer(): void
    {
        $this->user('first-admin@domain.pl', UserRole::Admin);
        $this->user('second-admin@domain.pl', UserRole::Admin);
        $this->user('customer@domain.pl', UserRole::Customer);
        $product = $this->stockedProduct(quantity: 10, reserved: 8, threshold: 3);

        $this->handle($this->returnLowStockAlertFor($product));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEqualsCanonicalizing(
            ['first-admin@domain.pl', 'second-admin@domain.pl'],
            array_map(static fn (Address $to): string => $to->getAddress(), $email->getTo()),
        );
        self::assertEmailTextBodyContains($email, 'Hammer');
        self::assertEmailTextBodyContains($email, 'SKU-1');
        self::assertEmailTextBodyContains($email, 'Available: 2');
        self::assertEmailTextBodyContains($email, 'Threshold: 3');
    }

    // admin restocked between the dispatch and the worker picking the message
    public function testStockReplenishedSinceDispatchSendsNothing(): void
    {
        $this->user('admin@domain.pl', UserRole::Admin);
        $product = $this->stockedProduct(quantity: 10, reserved: 8, threshold: 3);
        $message = $this->returnLowStockAlertFor($product);

        $inventory = $product->getInventory();
        self::assertNotNull($inventory);
        $inventory->setQuantity(50);
        $this->em->flush();

        $this->handle($message);

        self::assertEmailCount(0);
    }

    // product was removed between the dispatch and the worker picking the message
    public function testDeletedProductSendsNothing(): void
    {
        $this->user('admin@domain.pl', UserRole::Admin);
        $product = $this->stockedProduct(quantity: 10, reserved: 8, threshold: 3);
        $message = $this->returnLowStockAlertFor($product);

        $this->em->remove($product);
        $this->em->flush();
        $this->em->clear();

        $this->handle($message);

        self::assertEmailCount(0);
    }

    public function testWithoutAdminsNoMailIsSend(): void
    {
        $this->user('customer@domain.pl', UserRole::Customer);
        $product = $this->stockedProduct(quantity: 10, reserved: 8, threshold: 3);

        $this->handle($this->returnLowStockAlertFor($product));

        self::assertEmailCount(0);
    }
}
