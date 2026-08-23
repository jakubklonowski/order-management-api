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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AuthController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $users,
        EntityManagerInterface $em,
    ): JsonResponse {
        try {
            $dto = $serializer->deserialize($request->getContent(), RegisterRequest::class, 'json');
        } catch (\Throwable) {
            return $this->json(['error' => 'Malformed JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $validator->validate($dto);

        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()][] = $violation->getMessage();
            }

            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null !== $users->findOneByEmail($dto->getEmail())) {
            return $this->json(['error' => 'Email already registered.'], Response::HTTP_CONFLICT);
        }

        $user = new User($dto->getEmail());
        $user->setPassword($passwordHasher->hashPassword($user, $dto->getPassword()));

        try {
            $em->persist($user);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // email uniqueness catch in case something happened between check and write
            return $this->json(['error' => 'Email already registered.'], Response::HTTP_CONFLICT);
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
