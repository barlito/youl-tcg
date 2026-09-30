<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MarketPurchaseRepository;
use App\Service\Market\MarketPurchaseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:coin:reconcile-market', description: 'Resume the market purchases whose Youl Coin step (payment, seller payout, refund) is still unconfirmed')]
final class ReconcileMarketCommand extends Command
{
    public function __construct(
        private readonly MarketPurchaseService $purchaseService,
        private readonly MarketPurchaseRepository $purchaseRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = \count($this->purchaseRepository->findUnsettled());

        $this->purchaseService->reconcile();

        $io->success(\sprintf('%d unsettled market purchase(s), %d resolved.', $before, $before - \count($this->purchaseRepository->findUnsettled())));

        return Command::SUCCESS;
    }
}
