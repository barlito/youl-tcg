<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Realtime\UserEventEnum;
use App\Repository\DiscordUserRepository;
use App\Service\Realtime\UserEventPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

// Dev-only tooling: push a realtime toast to a player to check the Mercure chain.
#[When(env: 'dev')]
#[AsCommand(
    name: 'app:dev:notify',
    description: 'Push a realtime toast to a player (dev only, checks the Mercure chain)',
)]
class NotifyCommand extends Command
{
    public function __construct(
        private readonly DiscordUserRepository $userRepository,
        private readonly UserEventPublisher $userEventPublisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('discordId', InputArgument::REQUIRED, 'Discord id of the recipient')
            ->addArgument('message', InputArgument::OPTIONAL, 'Toast message', 'Test temps réel : ça marche !')
            ->addOption('link', null, InputOption::VALUE_REQUIRED, 'Internal link opened by the toast')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $discordId = (string) $input->getArgument('discordId');
        $user = $this->userRepository->find($discordId);

        if (null === $user) {
            $output->writeln(\sprintf('User "%s" not found.', $discordId));

            return Command::FAILURE;
        }

        $payload = ['title' => 'Dev', 'message' => (string) $input->getArgument('message')];
        if (\is_string($link = $input->getOption('link'))) {
            $payload['link'] = $link;
        }

        $this->userEventPublisher->publish($user, UserEventEnum::TOAST, $payload);
        $output->writeln(\sprintf('Toast pushed to %s.', $user->getUsername()));

        return Command::SUCCESS;
    }
}
