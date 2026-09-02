<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\ClearsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminCommandTest extends KernelTestCase
{
    use ClearsDatabase;

    private const EMAIL = 'admin@domain.pl';
    private const PASSWORD = 'pass1234';

    private CommandTester $tester;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase($this->em);

        $this->tester = new CommandTester(
            (new Application(static::$kernel))->find('app:create-admin')
        );
    }

    private function runCommand(string $email = self::EMAIL, string $password = self::PASSWORD, ?string $confirm = null): int
    {
        $this->tester->setInputs([$password, $confirm ?? $password]);

        return $this->tester->execute(['email' => $email]);
    }

    private function assertUsersStored(int $expected): void
    {
        $this->em->clear();
        self::assertCount($expected, $this->em->getRepository(User::class)->findAll());
    }

    public function testCreatesUserWithAdminRole(): void
    {
        self::assertSame(Command::SUCCESS, $this->runCommand());

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneByEmail(self::EMAIL);

        self::assertNotNull($user);
        self::assertSame(UserRole::Admin, $user->getRole());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testStoresHashedPassword(): void
    {
        self::assertSame(Command::SUCCESS, $this->runCommand());

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneByEmail(self::EMAIL);

        self::assertStringStartsWith('$', $user->getPassword());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::PASSWORD));
        self::assertStringNotContainsString(self::PASSWORD, $this->tester->getDisplay());
    }

    public function testRejectsMismatchedConfirmation(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(confirm: 'different'));

        $this->assertUsersStored(0);
    }

    public function testRejectsDuplicateEmail(): void
    {
        self::assertSame(Command::SUCCESS, $this->runCommand());
        self::assertSame(Command::FAILURE, $this->runCommand());

        $this->assertUsersStored(1);
    }

    public function testRejectsInvalidEmail(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(email: 'not-an-email'));

        $this->assertUsersStored(0);
    }

    public function testRejectsShortPassword(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(password: 'short'));

        $this->assertUsersStored(0);
    }

    public function testRejectsWhitespaceOnlyPassword(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(password: str_repeat(' ', 8)));

        $this->assertUsersStored(0);
    }

    public function testFailsWithoutAnInteractiveTerminal(): void
    {
        $exitCode = $this->tester->execute(['email' => self::EMAIL], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);

        $this->assertUsersStored(0);
    }

    public function testTrimsSurroundingWhitespaceFromEmail(): void
    {
        self::assertSame(Command::SUCCESS, $this->runCommand(email: '  '.self::EMAIL.'  '));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->findOneByEmail(self::EMAIL));
    }

    public function testTreatsPaddedEmailAsDuplicate(): void
    {
        self::assertSame(Command::SUCCESS, $this->runCommand());
        self::assertSame(Command::FAILURE, $this->runCommand(email: '  '.self::EMAIL.'  '));

        $this->assertUsersStored(1);
    }
}
