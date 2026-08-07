<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DiscordUserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

// Dev-only tooling: forge a JWT cookie for browser-based visual testing (Playwright).
// #[When(env: 'dev')] keeps the command unregistered in prod/test containers.
#[When(env: 'dev')]
#[AsCommand(
    name: 'app:dev:forge-jwt',
    description: 'Forge a JWT for a given Discord user (dev only, for browser testing)',
)]
class ForgeJwtCommand extends Command
{
    public function __construct(
        private readonly DiscordUserRepository $userRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('discordId', InputArgument::OPTIONAL, 'Discord id of the user', '188967649332428800');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $discordId = $input->getArgument('discordId');
        $user = $this->userRepository->find($discordId);

        if (null === $user) {
            $output->writeln(\sprintf('User "%s" not found.', $discordId));

            return Command::FAILURE;
        }

        $output->writeln($this->jwtManager->create($user));

        return Command::SUCCESS;
    }
}
