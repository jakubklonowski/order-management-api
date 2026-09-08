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
    public const CANCEL = 'ORDER_CANCEL';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::CANCEL], true) && ($subject instanceof Order);
    }

    // $attribute is not read - both permissions are handled the same way
    // today and supports() already rejected anything else
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
