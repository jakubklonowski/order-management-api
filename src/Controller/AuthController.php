<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth')]
#[Security(name: null)]
final class AuthController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    #[OA\Response(
        response: 201,
        description: 'The customer account was created.',
        content: new OA\JsonContent(
            required: ['id', 'email', 'role'],
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'email', type: 'string'),
                new OA\Property(property: 'role', type: 'string', example: 'customer'),
            ],
        ),
    )]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
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

    // login route exists only so router won't return 404 before firewall's json_login authenticator intercepts request
    // actual login logic is in config/packages/security.yaml (json_login + Lexik's success/failure handlers)
    // OA attributes below document that handler and must be kept in sync with it by hand
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'admin@example.com'),
                new OA\Property(property: 'password', type: 'string'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'A JWT to send as `Authorization: Bearer <token>`.',
        content: new OA\JsonContent(
            required: ['token'],
            properties: [new OA\Property(property: 'token', type: 'string')],
        ),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/InvalidCredentials')]
    public function login(): JsonResponse
    {
        throw new \LogicException('Handled by the json_login authenticator.');
    }
}
