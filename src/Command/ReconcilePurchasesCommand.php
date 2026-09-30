<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\BoosterPurchaseRepository;
use App\Service\Booster\BoosterPurchaseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:coin:reconcile-purchases', description: 'Resolve the booster purchases whose Youl Coin payment outcome was uncertain')]
final class ReconcilePurchasesCommand extends Command
{
    public function __construct(
        private readonly BoosterPurchaseService $purchaseService,
        private readonly BoosterPurchaseRepository $purchaseRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = \count($this->purchaseRepository->findPending());

        $this->purchaseService->reconcilePending();

        $io->success(\sprintf('%d pending purchase(s), %d resolved.', $before, $before - \count($this->purchaseRepository->findPending())));

        return Command::SUCCESS;
    }
}
