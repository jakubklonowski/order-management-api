<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $dto,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $users,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (null !== $users->findOneByEmail($dto->getEmail())) {
            return $this->json(['errors' => ['email' => ['Email already registered.']]], Response::HTTP_CONFLICT);
        }

        $user = new User($dto->getEmail());
        $user->setPassword($passwordHasher->hashPassword($user, $dto->getPassword()));

        try {
            $em->persist($user);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // email uniqueness catch in case something happened between check and write
            return $this->json(['errors' => ['email' => ['Email already registered.']]], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()->value,
        ], Response::HTTP_CREATED);
    }

    // login route exists only so router wont return 404 before firewall's json_login authenticator intercepts request
    // actual login logic is in /config/packages/security.yaml
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('Handled by the json_login authenticator.');
    }
}
