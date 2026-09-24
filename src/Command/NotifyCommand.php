<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Enum\Realtime\UserEventEnum;
use App\Repository\DiscordUserRepository;
use App\Service\Notification\NotificationService;
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
    description: 'Push a realtime toast or store a test notification (dev only, checks the Mercure chain)',
)]
class NotifyCommand extends Command
{
    public function __construct(
        private readonly DiscordUserRepository $userRepository,
        private readonly UserEventPublisher $userEventPublisher,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('discordId', InputArgument::REQUIRED, 'Discord id of the recipient')
            ->addArgument('message', InputArgument::OPTIONAL, 'Toast message', 'Test temps réel : ça marche !')
            ->addOption('link', null, InputOption::VALUE_REQUIRED, 'Internal link opened by the toast')
            ->addOption('notification', null, InputOption::VALUE_REQUIRED, 'Store a real notification instead: unique (broadcast, the user as puller), streak, credited')
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

        $kind = $input->getOption('notification');
        if (\is_string($kind)) {
            $notification = match ($kind) {
                'unique' => $this->notificationService->notify(null, NotificationTypeEnum::UNIQUE_PULLED, [
                    'playerId' => $user->getDiscordId(),
                    'playerName' => $user->getUsername(),
                    'universe' => 'Univers de test',
                ]),
                'streak' => $this->notificationService->notify($user, NotificationTypeEnum::STREAK_REWARD_AVAILABLE, ['milestone' => 7]),
                'credited' => $this->notificationService->notify($user, NotificationTypeEnum::BOOSTER_CREDITED, ['boosterName' => 'Pack de test', 'quantity' => 2, 'channel' => 'code']),
                default => null,
            };
            $output->writeln($notification instanceof Notification ? \sprintf('Notification "%s" stored and pushed.', $kind) : \sprintf('Unknown or failed notification "%s".', $kind));

            return $notification instanceof Notification ? Command::SUCCESS : Command::FAILURE;
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
