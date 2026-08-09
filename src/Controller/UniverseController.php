<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\BoosterRepository;
use App\Repository\CardRepository;
use App\Repository\ExtensionRepository;
use App\Repository\UserBoosterRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The universe pages: an index of the published extensions and one pokédex-style
 * page per extension (banners, personal completion, the full set with unowned
 * cards masked, related boosters, unique 1/1 drop status).
 */
class UniverseController extends AbstractController
{
    /**
     * Memo: ownedBoosterCounts serves both the visibility filter and the
     * template, one query instead of two.
     *
     * @var array<string, int>|null
     */
    private ?array $ownedBoosterCountsCache = null;

    public function __construct(
        private readonly ExtensionRepository $extensionRepository,
        private readonly BoosterRepository $boosterRepository,
        private readonly CardRepository $cardRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly BoosterAvailabilityService $boosterAvailability,
    ) {
    }

    #[Route('/univers', name: 'universes')]
    public function index(#[CurrentUser] DiscordUser $user): Response
    {
        $extensions = $this->extensionRepository->findPublishedWithPublishedCardCount();
        $ownedByExtension = $this->userCardRepository->countOwnedGroupedByExtension($user);
        $coverImages = $this->cardRepository->findCoverImageNamesByExtension();

        $universes = array_map(static function (array $item) use ($ownedByExtension, $coverImages): array {
            $extensionId = (string) $item['extension']->getId();
            $owned = $ownedByExtension[$extensionId] ?? 0;

            return [
                'extension' => $item['extension'],
                'total' => $item['cardCount'],
                'owned' => $owned,
                'percentage' => $item['cardCount'] > 0 ? (int) round($owned / $item['cardCount'] * 100) : 0,
                'coverImage' => $coverImages[$extensionId] ?? null,
                'hasClaimableBooster' => $item['hasClaimableBooster'],
            ];
        }, $extensions);

        return $this->render('pages/universes.html.twig', [
            'universes' => $universes,
            'upcoming' => $this->extensionRepository->findUpcoming(),
        ]);
    }

    #[Route('/univers/{slug}', name: 'universes_show', requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(#[CurrentUser] DiscordUser $user, string $slug): Response
    {
        $extension = $this->extensionRepository->findOneBy([
            'slug' => $slug,
            'status' => ExtensionStatusEnum::PUBLISHED,
        ]);

        if (!$extension instanceof Extension) {
            throw $this->createNotFoundException(\sprintf('Unknown universe "%s".', $slug));
        }

        $catalog = $this->sortRarestFirst($this->cardRepository->findPublishedByExtension($extension));

        // owned rows keyed by card id: the set grid shows owned cards, masks the rest
        $ownedByCardId = [];
        foreach ($this->userCardRepository->findOwnedWithCards($user, $extension) as $userCard) {
            $ownedByCardId[(string) $userCard->getCard()->getId()] = $userCard;
        }

        $uniquesTotal = 0;
        $uniquesClaimed = 0;
        foreach ($catalog as $card) {
            if ($card->isUnique()) {
                ++$uniquesTotal;
                $uniquesClaimed += $card->isClaimed() ? 1 : 0;
            }
        }

        return $this->render('pages/universe.html.twig', [
            'extension' => $extension,
            'catalog' => $catalog,
            'ownedByCardId' => $ownedByCardId,
            'boosters' => $this->visibleBoosters($extension, $user),
            'ownedBoosterCounts' => $this->ownedBoosterCounts($user),
            'uniquesTotal' => $uniquesTotal,
            'uniquesClaimed' => $uniquesClaimed,
            'coverImage' => $this->cardRepository->findCoverImageNamesByExtension()[(string) $extension->getId()] ?? null,
        ]);
    }

    /**
     * Same visibility rule as the hub, via BoosterAvailabilityService:
     * claimable boosters, plus non-claimable ones (event / code) the user
     * actually owns copies of.
     *
     * @return list<Booster>
     */
    private function visibleBoosters(Extension $extension, DiscordUser $user): array
    {
        return $this->boosterAvailability->filterVisible(
            $this->boosterRepository->findBy(['extension' => $extension], ['name' => 'ASC', 'id' => 'ASC']),
            $this->ownedBoosterCounts($user),
        );
    }

    /**
     * @return array<string, int> booster id => owned quantity
     */
    private function ownedBoosterCounts(DiscordUser $user): array
    {
        if (null !== $this->ownedBoosterCountsCache) {
            return $this->ownedBoosterCountsCache;
        }

        $counts = [];
        foreach ($this->userBoosterRepository->findBy(['discordUser' => $user]) as $userBooster) {
            $counts[(string) $userBooster->getBooster()->getId()] = $userBooster->getQuantity();
        }

        return $this->ownedBoosterCountsCache = $counts;
    }

    /**
     * Pokédex order: rarest first, name as tiebreak — the same ranking as the
     * opening aside, so the set reads in the same order everywhere.
     *
     * @param list<Card> $cards
     *
     * @return list<Card>
     */
    private function sortRarestFirst(array $cards): array
    {
        usort(
            $cards,
            static fn (Card $a, Card $b): int => CardRarityEnum::compareRarestFirst($a->getRarity(), $b->getRarity())
                ?: $a->getName() <=> $b->getName(),
        );

        return $cards;
    }
}
