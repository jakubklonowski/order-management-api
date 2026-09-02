<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class ApiTestCase extends WebTestCase
{
    use ClearsDatabase;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->clearDatabase($this->em);
    }

    protected function request(string $method, string $uri, ?string $body = null, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request($method, $uri, server: $server, content: $body);
    }

    /**
     * @return array<mixed>
     */
    protected function responseBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    protected function createUser(string $email, UserRole $role = UserRole::Customer, string $password = 'pass1234'): User
    {
        $user = new User($email, $role);
        $user->setPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password)
        );

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    // get token directly instead of api route for tests to not depend on login endpoint working as well
    protected function tokenFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    protected function tokenForNewUser(string $email, UserRole $role = UserRole::Customer): string
    {
        return $this->tokenFor($this->createUser($email, $role));
    }
}
