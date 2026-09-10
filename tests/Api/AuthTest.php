<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthTest extends ApiTestCase
{
    private const EMAIL = 'user@domain.pl';
    private const PASSWORD = 'pass1234';

    private function post(string $uri, string $body): void
    {
        $this->request('POST', $uri, $body);
    }

    private function json(string $email = self::EMAIL, string $password = self::PASSWORD): string
    {
        return json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR);
    }

    private function assertUsersStored(int $expected): void
    {
        $this->em->clear();
        self::assertCount($expected, $this->em->getRepository(User::class)->findAll());
    }

    public function testRegisterCreatesUser(): void
    {
        $this->post('/api/register', $this->json());

        self::assertResponseStatusCodeSame(201);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        // in case of controller changes makes password leak
        self::assertEqualsCanonicalizing(['id', 'email', 'role'], array_keys($response));
        self::assertSame(self::EMAIL, $response['email']);
        self::assertSame('customer', $response['role']);
    }

    public function testRegisterStoresHashedPassword(): void
    {
        $this->post('/api/register', $this->json());
        self::assertResponseStatusCodeSame(201);

        // clear EntityManager in case non-persisted entities from the same process exists inside it
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneByEmail(self::EMAIL);

        self::assertNotNull($user);
        // catches plaintext
        self::assertStringStartsWith('$', $user->getPassword());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::PASSWORD));
        self::assertSame(UserRole::Customer, $user->getRole());
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $this->post('/api/register', $this->json());
        self::assertResponseStatusCodeSame(201);

        $this->post('/api/register', $this->json());
        self::assertResponseStatusCodeSame(409);

        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['email'], array_keys($response['errors']));

        $this->assertUsersStored(1);
    }

    public function testRegisterRejectsInvalidPayload(): void
    {
        $this->post('/api/register', $this->json('not-an-email', 'short'));

        self::assertResponseStatusCodeSame(422);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('email', $response['errors']);
        self::assertArrayHasKey('password', $response['errors']);
        $this->assertUsersStored(0);
    }

    public function testRegisterRejectsMalformedJson(): void
    {
        $this->post('/api/register', '{"email":');

        self::assertResponseStatusCodeSame(400);
        $this->assertUsersStored(0);
    }

    public static function incompletePayloads(): iterable
    {
        yield 'password key absent' => ['{"email":"user@domain.pl"}', ['password']];
        yield 'email key absent' => ['{"password":"pass1234"}', ['email']];
        yield 'empty object' => ['{}', ['email', 'password']];
    }

    #[DataProvider('incompletePayloads')]
    public function testRegisterRejectsIncompletePayload(string $body, array $expectedInvalidFields): void
    {
        $this->post('/api/register', $body);

        self::assertResponseStatusCodeSame(422);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertEqualsCanonicalizing($expectedInvalidFields, array_keys($response['errors']));
        $this->assertUsersStored(0);
    }

    public function testRegisterRejectsWhitespaceOnlyPassword(): void
    {
        $this->post('/api/register', $this->json(password: str_repeat(' ', 8)));

        self::assertResponseStatusCodeSame(422);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('password', $response['errors']);
        $this->assertUsersStored(0);
    }

    public function testRegisterTrimsSurroundingWhitespaceFromEmail(): void
    {
        $this->post('/api/register', $this->json(email: '  '.self::EMAIL.'  '));

        self::assertResponseStatusCodeSame(201);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(self::EMAIL, $response['email']);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->findOneByEmail(self::EMAIL));
    }

    public function testRegisterTreatsPaddedEmailAsDuplicate(): void
    {
        $this->post('/api/register', $this->json());
        self::assertResponseStatusCodeSame(201);

        $this->post('/api/register', $this->json(email: '  '.self::EMAIL.'  '));
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegisterRejectsNullValues(): void
    {
        $this->post('/api/register', '{"email":null,"password":null}');

        self::assertResponseStatusCodeSame(422);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertEqualsCanonicalizing(['email', 'password'], array_keys($response['errors']));
        $this->assertUsersStored(0);
    }

    public function testRegisterIgnoresExtraFields(): void
    {
        $this->post('/api/register', json_encode([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'role' => 'admin',
            'id' => 999,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('customer', $response['role']);
        self::assertNotSame(999, $response['id']);

        $this->em->clear();
        self::assertSame(UserRole::Customer, $this->em->getRepository(User::class)->findOneByEmail(self::EMAIL)->getRole());
    }

    public function testLoginReturnsUsableToken(): void
    {
        $this->post('/api/register', $this->json());
        self::assertResponseStatusCodeSame(201);

        $this->post('/api/login', $this->json());
        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('token', $response);

        // signature and claims are verified through Lexik
        $claims = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->parse($response['token']);

        self::assertSame(self::EMAIL, $claims['username']);
        self::assertContains('ROLE_CUSTOMER', $claims['roles']);
        self::assertGreaterThan($claims['iat'], $claims['exp']);
    }

    public function testLoginUpgradesAnOutdatedPasswordHash(): void
    {
        $user = new User(self::EMAIL, UserRole::Customer);
        $user->setPassword(password_hash(self::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 6]));
        $this->em->persist($user);
        $this->em->flush();
        $stale = $user->getPassword();

        $this->post('/api/login', $this->json());
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneByEmail(self::EMAIL);

        self::assertNotSame($stale, $stored->getPassword());
        // upgrade must not invalidate credential it rehashed
        self::assertTrue(
            static::getContainer()->get(UserPasswordHasherInterface::class)
                ->isPasswordValid($stored, self::PASSWORD)
        );
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->post('/api/register', $this->json());
        self::assertResponseStatusCodeSame(201);

        $this->post('/api/login', $this->json(password: 'wrong-password'));
        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginRejectsUnknownEmail(): void
    {
        $this->post('/api/login', $this->json('nobody@example.com'));

        self::assertResponseStatusCodeSame(401);
    }
}
