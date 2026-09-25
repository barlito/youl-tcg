<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\TradeLineRequest;
use App\Entity\DiscordUser;
use App\Entity\TradeOffer;
use App\Exception\Trade\TradeException;
use App\Repository\DiscordUserRepository;
use App\Repository\TradeOfferRepository;
use App\Service\Trade\TradeOfferService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

// Dev-only tooling: drive a trade through the real service (realtime included) without a browser.
#[When(env: 'dev')]
#[AsCommand(name: 'app:dev:trade', description: 'Create a one-card-each-side offer, or resolve one, through TradeOfferService (dev only)', help: <<<'TXT'
<info>app:dev:trade offer 188967649332428800 232457563910832129</info>   one free copy of the proposer against one copy of the receiver
<info>app:dev:trade refuse <offerId></info>                              as the receiver (accept too); cancel acts as the proposer
TXT)]
class TradeCommand extends Command
{
    public function __construct(
        private readonly DiscordUserRepository $userRepository,
        private readonly TradeOfferRepository $tradeOfferRepository,
        private readonly TradeOfferService $tradeOfferService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'offer | accept | refuse | cancel')
            ->addArgument('first', InputArgument::REQUIRED, 'offer: proposer discord id — otherwise: offer id')
            ->addArgument('second', InputArgument::OPTIONAL, 'offer: receiver discord id')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $first = (string) $input->getArgument('first');

        try {
            if ('offer' === $action) {
                $offer = $this->offer($this->user($first), $this->user((string) $input->getArgument('second')));
                $output->writeln(\sprintf('Offer %s created.', $offer->getId()));

                return Command::SUCCESS;
            }

            $offer = Uuid::isValid($first) ? $this->tradeOfferRepository->find($first) : null;
            if (!$offer instanceof TradeOffer) {
                throw new \InvalidArgumentException(\sprintf('Offer "%s" not found.', $first));
            }

            match ($action) {
                'accept' => $this->tradeOfferService->accept($offer, $offer->getReceiver()),
                'refuse' => $this->tradeOfferService->refuse($offer, $offer->getReceiver()),
                'cancel' => $this->tradeOfferService->cancel($offer, $offer->getProposer()),
                default => throw new \InvalidArgumentException(\sprintf('Unknown action "%s".', $action)),
            };
            $output->writeln(\sprintf('Offer %s: %s.', $offer->getId(), $offer->getStatus()->label()));

            return Command::SUCCESS;
        } catch (TradeException $exception) {
            $output->writeln($exception->getUserMessage());
        } catch (\InvalidArgumentException $exception) {
            $output->writeln($exception->getMessage());
        }

        return Command::FAILURE;
    }

    /**
     * Prefers cards the receiver does not own yet: the offer then shows the masking.
     */
    private function offer(DiscordUser $proposer, DiscordUser $receiver): TradeOffer
    {
        $engageable = $this->tradeOfferService->getEngageableCopies($proposer);
        $requestable = $this->tradeOfferService->getRequestableCopies($receiver);
        $unknownToReceiver = array_diff_key($engageable, $requestable);
        $offered = [] !== $unknownToReceiver ? reset($unknownToReceiver) : reset($engageable);
        $requested = reset($requestable);

        if (false === $offered || false === $requested) {
            throw new \InvalidArgumentException('Nothing to trade between these players.');
        }

        return $this->tradeOfferService->create(
            $proposer,
            $receiver,
            [new TradeLineRequest($offered['card'], $offered['normal'] > 0 ? 1 : 0, $offered['normal'] > 0 ? 0 : 1)],
            [new TradeLineRequest($requested['card'], $requested['normal'] > 0 ? 1 : 0, $requested['normal'] > 0 ? 0 : 1)],
        );
    }

    private function user(string $discordId): DiscordUser
    {
        return $this->userRepository->find($discordId) ?? throw new \InvalidArgumentException(\sprintf('User "%s" not found.', $discordId));
    }
}
