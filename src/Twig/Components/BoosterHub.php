<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\DiscordUser;
use App\Exception\Booster\BoosterException;
use App\Repository\BoosterRepository;
use App\Repository\UserBoosterRepository;
use App\Service\Booster\BoosterClaimService;
use App\Service\Booster\BoosterOpeningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsLiveComponent]
final class BoosterHub extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?BoosterOpening $opening = null;

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly BoosterRepository $boosterRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly BoosterClaimService $boosterClaimService,
        private readonly BoosterOpeningService $boosterOpeningService,
    ) {
    }

    /**
     * @return list<Booster>
     */
    public function getBoosters(): array
    {
        return $this->boosterRepository->findPublished();
    }

    public function getRemainingClaims(): int
    {
        return $this->boosterClaimService->getRemainingClaims($this->getDiscordUser());
    }

    public function getNextResetTime(): \DateTimeImmutable
    {
        return $this->boosterClaimService->getNextResetTime();
    }

    /**
     * @return array<string, int> booster id => owned quantity
     */
    public function getInventory(): array
    {
        $inventory = [];

        foreach ($this->userBoosterRepository->findBy(['discordUser' => $this->getDiscordUser()]) as $userBooster) {
            $inventory[(string) $userBooster->getBooster()->getId()] = $userBooster->getQuantity();
        }

        return $inventory;
    }

    #[LiveAction]
    public function claimBooster(#[LiveArg] string $boosterId): void
    {
        $this->error = null;

        $booster = $this->boosterRepository->find($boosterId);

        if (null === $booster) {
            $this->error = 'Booster introuvable.';

            return;
        }

        try {
            $this->boosterClaimService->claim($this->getDiscordUser(), $booster);
        } catch (BoosterException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    #[LiveAction]
    public function openBooster(#[LiveArg] string $boosterId): void
    {
        $this->error = null;

        $booster = $this->boosterRepository->find($boosterId);

        if (null === $booster) {
            $this->error = 'Booster introuvable.';

            return;
        }

        try {
            $this->opening = $this->boosterOpeningService->open($this->getDiscordUser(), $booster);
        } catch (BoosterException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->opening = null;
        $this->error = null;
    }

    private function getDiscordUser(): DiscordUser
    {
        $user = $this->getUser();

        if (!$user instanceof DiscordUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
