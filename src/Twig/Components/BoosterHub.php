<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Booster;
use App\Entity\DiscordUser;
use App\Exception\Booster\BoosterException;
use App\Repository\BoosterRepository;
use App\Repository\UserBoosterRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Booster\BoosterClaimService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class BoosterHub extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $error = null;

    /**
     * Inventory is read twice per render (hero total + packs grid): memoize
     * the query for the lifetime of the (per-request) component instance.
     *
     * @var array<string, int>|null
     */
    private ?array $inventory = null;

    public function __construct(
        private readonly BoosterRepository $boosterRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly BoosterAvailabilityService $boosterAvailability,
        private readonly BoosterClaimService $boosterClaimService,
    ) {
    }

    /**
     * The hub list: the published boosters the user is allowed to see
     * (claimable, or event/code ones they already own copies of).
     *
     * @return list<Booster>
     */
    public function getBoosters(): array
    {
        return $this->boosterAvailability->filterVisible(
            $this->boosterRepository->findPublished(),
            $this->getInventory(),
        );
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
     * Server-computed remaining seconds before the quota reset, so the
     * client countdown never depends on the client clock.
     */
    public function getSecondsUntilReset(): int
    {
        return max(0, $this->getNextResetTime()->getTimestamp() - time());
    }

    /**
     * Booster ids that can actually be opened (their extension has at least
     * one published card). Boosters over an empty extension are surfaced as
     * "à venir" rather than letting the user hit a draw error.
     *
     * @return array<string, true> booster id => true
     */
    public function getDrawableBoosterIds(): array
    {
        return $this->boosterAvailability->drawableBoosterIds($this->getBoosters());
    }

    /**
     * @return array<string, int> booster id => owned quantity
     */
    public function getInventory(): array
    {
        if (null !== $this->inventory) {
            return $this->inventory;
        }

        $inventory = [];

        foreach ($this->userBoosterRepository->findBy(['discordUser' => $this->getDiscordUser()]) as $userBooster) {
            $inventory[(string) $userBooster->getBooster()->getId()] = $userBooster->getQuantity();
        }

        return $this->inventory = $inventory;
    }

    /**
     * Total unopened boosters across every pack type, for the hero counter.
     */
    public function getTotalOwned(): int
    {
        return array_sum($this->getInventory());
    }

    #[LiveAction]
    public function claimBooster(#[LiveArg] string $boosterId): void
    {
        $this->error = null;

        $booster = $this->findBooster($boosterId);

        if (!$booster instanceof Booster) {
            $this->error = 'Booster introuvable.';

            return;
        }

        try {
            $this->boosterClaimService->claim($this->getDiscordUser(), $booster);
            $this->inventory = null; // the memoized inventory is stale after a claim
        } catch (BoosterException $exception) {
            $this->error = $exception->getUserMessage();
        }
    }

    /**
     * The id is client-provided (LiveArg): a malformed uuid must resolve to
     * "not found" instead of a Doctrine conversion error.
     */
    private function findBooster(string $boosterId): ?Booster
    {
        if (!Uuid::isValid($boosterId)) {
            return null;
        }

        return $this->boosterRepository->find($boosterId);
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
