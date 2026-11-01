<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OrderPlaced;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class SendOrderConfirmation
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(OrderPlaced $message): void
    {
        $order = $this->orders->find($message->orderId) ??
            throw new UnrecoverableMessageHandlingException(sprintf('Order %d does not exist.', $message->orderId));

        // idempotency guard
        if (null !== $order->getConfirmationSentAt()) {
            return;
        }

        $this->mailer->send(
            (new TemplatedEmail())
                ->to($order->getUser()->getEmail())
                ->subject(sprintf('Order #%d confirmation', $message->orderId))
                ->textTemplate('email/order_confirmation.txt.twig')
                ->context(['order' => $order])
        );

        $order->markConfirmationSent(new \DateTimeImmutable());
        $this->em->flush();
    }
}
