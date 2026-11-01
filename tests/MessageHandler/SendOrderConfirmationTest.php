<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Message\OrderPlaced;
use App\Tests\ClearsDatabase;
use App\Tests\UsesMessenger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Mime\Email;

final class SendOrderConfirmationTest extends KernelTestCase
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

    private function placedOrder(): int
    {
        $customer = new User('customer@domain.pl');
        $category = new Category('Tools');
        $product = new Product('Hammer', '19.99', 'SKU-1', $category);

        $order = new Order($customer);
        $order->addItem($product, 2, '19.99');
        $order->setTotalPrice('39.98');

        $this->em->persist($customer);
        $this->em->persist($category);
        $this->em->persist($product);
        $this->em->persist($order);
        $this->em->flush();

        $orderId = $order->getId();
        self::assertNotNull($orderId);

        return $orderId;
    }

    public function testMailsTheCustomerAndStoresWhenItWasSent(): void
    {
        $orderId = $this->placedOrder();

        $this->handle(new OrderPlaced($orderId));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'customer@domain.pl');

        // the handler worked on the same entity manager so the object would look right
        // even without a flush - clear it and read the row
        $this->em->clear();
        $stored = $this->em->find(Order::class, $orderId);
        self::assertNotNull($stored);
        self::assertNotNull($stored->getConfirmationSentAt());
    }

    public function testSecondDeliveryDoesNotMailAgain(): void
    {
        $orderId = $this->placedOrder();

        $this->handle(new OrderPlaced($orderId));
        $this->handle(new OrderPlaced($orderId));

        self::assertEmailCount(1);
    }

    public function testMissingOrderFailsWithoutBeingRetried(): void
    {
        try {
            $this->handle(new OrderPlaced(999999));
            self::fail('The handler should have thrown.');
        } catch (HandlerFailedException $exception) {
            // unrecoverable goes straight to the failed transport, any other exception is retried first
            self::assertCount(1, $exception->getWrappedExceptions(UnrecoverableMessageHandlingException::class));
        }

        self::assertEmailCount(0);
    }
}
