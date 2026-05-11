<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Repository\BoosterRepository;
use App\Service\BoosterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class BoosterListComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public bool $showModal = false;

    #[LiveProp]
    public array $openedCards = [];

    public function __construct(
        private readonly BoosterRepository $boosterRepository,
        private readonly BoosterService $boosterService,
    ) {
    }

    public function getBoosters(): array
    {
        return $this->boosterRepository->findAll();
    }

    public function getRemainingOpenings(): int
    {
        /** @var DiscordUser $user */
        $user = $this->getUser();

        return $this->boosterService->getRemainingFreeOpenings($user);
    }

    public function getNextAvailableTime(): ?\DateTimeImmutable
    {
        /** @var DiscordUser $user */
        $user = $this->getUser();

        return $this->boosterService->getNextAvailableTime($user);
    }

    #[LiveAction]
    public function openBooster(#[LiveArg] string $boosterId): void
    {
        /** @var DiscordUser $user */
        $user = $this->getUser();

        $booster = $this->boosterRepository->find($boosterId);

        if (!$booster) {
            $this->addFlash('error', 'Booster introuvable');

            return;
        }

        try {
            $cards = $this->boosterService->openBooster($user, $booster);

            // Transform cards to array for LiveProp
            $this->openedCards = array_map(function (Card $card) {
                return [
                    'id' => $card->getId(),
                    'name' => $card->getName(),
                    'imageName' => $card->getImageName(),
                    'uniqueFlag' => $card->isUnique(),
                ];
            }, $cards);

            $this->showModal = true;
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->openedCards = [];
    }
}
