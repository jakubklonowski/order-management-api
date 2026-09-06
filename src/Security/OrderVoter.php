<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Order>
 */
final class OrderVoter extends Voter
{
    public const VIEW = 'ORDER_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::VIEW === $attribute) && ($subject instanceof Order);
    }

    // $attribute not used as long as there's only one $attribute to
    // check and it was already checked in supports()
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if (UserRole::Admin === $user->getRole()) {
            return true;
        }

        return $subject->getUser()->getId() === $user->getId();
    }
}
