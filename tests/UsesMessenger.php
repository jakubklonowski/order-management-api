<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * Shared trait for every test case that reads the async queue or runs a message handler.
 * Needs a booted kernel, so it is meant for KernelTestCase and its subclasses.
 */
trait UsesMessenger
{
    /**
     * Messages waiting in the async queue, decoded from the rows MySQL holds, oldest first.
     *
     * @return list<object>
     */
    protected function queuedMessages(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        $messages = [];

        foreach ($transport->all() as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
    }

    // ReceivedStamp makes the bus skip the transport and run the handlers, so this goes through
    // the real handler wiring (including #[AsMessageHandler]) instead of calling __invoke directly
    protected function handle(object $message): void
    {
        static::getContainer()->get(MessageBusInterface::class)
            ->dispatch(new Envelope($message, [new ReceivedStamp('async')]));
    }
}
