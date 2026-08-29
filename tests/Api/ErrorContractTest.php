<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * class for testing error reporting contract
 */
final class ErrorContractTest extends WebTestCase
{
    private const EMAIL = 'contract@domain.pl';
    private const PASSWORD = 'pass1234';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM users');

        // every case starts with existing user so that duplicate test can reach 409
        $this->request('POST', '/api/register', self::registration());
    }

    private function request(string $method, string $uri, ?string $body): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    }

    private static function registration(): string
    {
        return json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return iterable<string, array{string, string, string|null, int}>
     */
    public static function failingRequests(): iterable
    {
        yield 'malformed json' => ['POST', '/api/register', '{"email":', 400];
        yield 'unknown route' => ['GET', '/api/nope', null, 404];
        yield 'wrong method' => ['GET', '/api/register', null, 405];
        yield 'duplicate email' => ['POST', '/api/register', self::registration(), 409];
        yield 'failed validation' => ['POST', '/api/register', '{"email":"nope","password":"x"}', 422];
    }

    #[DataProvider('failingRequests')]
    public function testEveryErrorSharesOneShape(string $method, string $uri, ?string $body, int $expectedStatus): void
    {
        $this->request($method, $uri, $body);

        self::assertResponseStatusCodeSame($expectedStatus);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['errors'], array_keys($response));
        self::assertNotEmpty($response['errors']);

        foreach ($response['errors'] as $messages) {
            self::assertIsList($messages);
            self::assertContainsOnlyString($messages);
            self::assertNotEmpty($messages);
        }
    }
}
