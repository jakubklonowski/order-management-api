<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\LowStockReached;
use App\Repository\InventoryRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendLowStockAlert
{
    public function __construct(
        private readonly InventoryRepository $inventories,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
    ) {
    }

    public function __invoke(LowStockReached $message): void
    {
        $inventory = $this->inventories->findOneBy(['product' => $message->productId]);

        // no row - product was deleted since dispatch which is a valid action
        // stock is not low any more - mail would report current numbers and this would be wrong
        if (null === $inventory || !$inventory->isLowStock()) {
            return;
        }

        $admins = $this->users->findAdmins();
        if ([] === $admins) {
            return;
        }

        $email = (new TemplatedEmail())
            ->subject(sprintf('Low stock: %s', $inventory->getProduct()->getName()))
            ->textTemplate('email/low_stock_alert.txt.twig')
            ->context(['inventory' => $inventory]);

        foreach ($admins as $admin) {
            $email->addTo($admin->getEmail());
        }

        $this->mailer->send($email);
    }
}
