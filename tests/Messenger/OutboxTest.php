<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\OrderPlaced;
use App\Tests\ClearsDatabase;
use App\Tests\UsesMessenger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Proves the queue is a transactional outbox - a message dispatched inside a transaction is
 * stored with it or discarded with it.
 */
final class OutboxTest extends KernelTestCase
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

    private function dispatch(): void
    {
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new OrderPlaced(1));
    }

    // without this the rollback case below stays green even if the Messenger routing is broken
    public function testDispatchInsideCommittedTransactionLeavesExactlyOneMessage(): void
    {
        $this->em->wrapInTransaction($this->dispatch(...));

        self::assertEquals([new OrderPlaced(1)], $this->queuedMessages());
    }

    public function testDispatchInsideRolledBackTransactionLeavesNoMessage(): void
    {
        try {
            $this->em->wrapInTransaction(function (): void {
                $this->dispatch();

                throw new \RuntimeException('rolled back on purpose');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->queuedMessages());
    }
}
