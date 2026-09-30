<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Repository\UniverseCompletionRewardRepository;
use App\Service\Coin\UniverseRewardService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:coin:pay-pending-rewards', description: 'Pay the universe completion rewards still pending (uncertain payment), optionally the failed ones too')]
final class PayPendingRewardsCommand extends Command
{
    public function __construct(
        private readonly UniverseRewardService $rewardService,
        private readonly UniverseCompletionRewardRepository $rewardRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Also retry the rewards the coin refused (empty bank…)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $includeFailed = (bool) $input->getOption('retry-failed');
        $before = \count($this->rewardRepository->findToPay(includeFailed: $includeFailed));

        $this->rewardService->payPending(includeFailed: $includeFailed);

        $io->success(\sprintf('%d reward(s) to pay, %d paid.', $before, $before - \count($this->rewardRepository->findToPay(includeFailed: $includeFailed))));
        $io->comment(\sprintf('%d failed reward(s) left.', $this->rewardRepository->count(['status' => UniverseRewardStatusEnum::FAILED])));

        return Command::SUCCESS;
    }
}
