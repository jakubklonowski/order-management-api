<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Creates user with admin role',
)]
final class CreateAdminCommand extends Command
{
    private ?string $password = null;
    private ?string $passwordConfirmation = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address for admin account');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        // not an argument nor option - password won't reach shell history
        $this->password = $this->askPassword($io, 'Password: ');
        $this->passwordConfirmation = $this->askPassword($io, 'Repeat password: ');
    }

    private function askPassword(SymfonyStyle $io, string $label): string
    {
        // askQuestion raises two problems regarding how AuthController handles the same data
        // askQuestion trims input and setting setTrimmable(false) leaves line endings
        // as a result askQuestion with setTrimmable(false) is used with it's
        // returned value rtrimmed from line terminator
        $question = (new Question($label))->setHidden(true)->setTrimmable(false);

        return rtrim((string) $io->askQuestion($question), "\r\n");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->password) {
            $io->error('This command needs an interactive terminal to ask for the password');

            return Command::FAILURE;
        }

        if ($this->password !== $this->passwordConfirmation) {
            $io->error('Passwords do not match');

            return Command::FAILURE;
        }

        // reusing api's validation for user credentials request
        $dto = new RegisterRequest();
        $dto->setEmail((string) $input->getArgument('email'));
        $dto->setPassword($this->password);
        $violations = $this->validator->validate($dto);

        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().': '.$violation->getMessage());
            }

            return Command::FAILURE;
        }

        if (null !== $this->users->findOneByEmail($dto->getEmail())) {
            $io->error(sprintf('A user with email "%s" already exists', $dto->getEmail()));

            return Command::FAILURE;
        }

        $user = new User($dto->getEmail(), UserRole::Admin);
        $user->setPassword($this->passwordHasher->hashPassword($user, $dto->getPassword()));

        try {
            $this->em->persist($user);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $io->error(sprintf('A user with email "%s" already exists.', $dto->getEmail()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Admin created: %s (id %d)', $user->getEmail(), $user->getId()));

        return Command::SUCCESS;
    }
}
