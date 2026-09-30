<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\UniverseCompletionReward;
use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\UniverseCompletionCatchUp;
use App\Service\Coin\UniverseCompletionChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:coin:grant-completed-universes', description: 'Reward the universes completed before the reward existed (run once at deployment, safe to replay)')]
final class GrantCompletedUniversesCommand extends Command
{
    public function __construct(
        private readonly UniverseCompletionCatchUp $catchUp,
        private readonly UniverseCompletionChecker $checker,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the rewards and the total to provision in the bank, write and pay nothing')
            ->addOption('player', null, InputOption::VALUE_REQUIRED, 'Only this discord id')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $player = $input->getOption('player');
        $missing = $this->catchUp->findMissing(\is_string($player) ? $player : null);

        if ([] === $missing) {
            $io->success('No completed universe without reward.');

            return Command::SUCCESS;
        }

        $total = 0;
        $rows = [];
        $statuses = [];
        foreach ($missing as $entry) {
            $status = 'à créer';
            if (!$dryRun) {
                $reward = $this->checker->rewardCompleted($entry['user'], $entry['extension']);
                $status = $reward instanceof UniverseCompletionReward ? $reward->getStatus()->name : 'déjà récompensé';
                if (!$reward instanceof UniverseCompletionReward) {
                    continue;
                }

                $statuses[$reward->getStatus()->name] = ($statuses[$reward->getStatus()->name] ?? 0) + 1;
            }

            $total += $entry['amount'];
            $rows[] = [$entry['user']->getUsername(), $entry['extension']->getName(), $entry['amount'], $status];
        }

        $io->table(['Joueur', 'Univers', 'Montant (YLC)', 'Statut'], $rows);
        $io->writeln(\sprintf('%d reward(s), total %s YLC (paid, pending and failed included).', \count($rows), CoinAmount::fromCoins($total)->format()));

        if ($dryRun) {
            $io->note('Dry run: nothing written, the coin was not called. Provision the bank with this total first.');

            return Command::SUCCESS;
        }

        if (($statuses[UniverseRewardStatusEnum::FAILED->name] ?? 0) > 0 || ($statuses[UniverseRewardStatusEnum::PENDING->name] ?? 0) > 0) {
            $io->warning('Some rewards are not paid: once the bank is refilled, run app:coin:pay-pending-rewards --retry-failed.');
        }

        return Command::SUCCESS;
    }
}
