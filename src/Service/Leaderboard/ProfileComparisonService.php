<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Dto\ProfileCardComparison;
use App\Dto\ProfileComparison;
use App\Dto\ProfileUniverseComparison;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\UserCard;
use App\Enum\ProfileCardStateEnum;
use App\Repository\CardRepository;
use App\Repository\UserCardRepository;

/**
 * Crosses the published catalogue with two collections — the visited profile's
 * and the visitor's — so a profile also shows what its owner is MISSING, and
 * what both are still hunting.
 *
 * Disclosure rules, in one place:
 *  - a card of the profile is revealed only when the visitor owns it too (the
 *    historical masking rule, untouched);
 *  - a card the profile does NOT own has nothing to hide, so it may be revealed
 *    as soon as the visitor owns it;
 *  - a 1/1 the visitor doesn't hold stays a MYSTERY tile: neither the artwork
 *    nor the ownership bit, and it is excluded from the counters so that a
 *    universe with a single unique cannot be solved by subtraction.
 *
 * Three queries whatever the catalogue size — never one per card or per player.
 */
final readonly class ProfileComparisonService
{
    public function __construct(
        private CardRepository $cardRepository,
        private UserCardRepository $userCardRepository,
    ) {
    }

    public function compare(DiscordUser $profile, DiscordUser $visitor): ProfileComparison
    {
        $isSelf = $profile->getDiscordId() === $visitor->getDiscordId();

        /** @var array<string, UserCard> $profileCards */
        $profileCards = [];
        foreach ($this->userCardRepository->findOwnedWithCards($profile) as $userCard) {
            $profileCards[(string) $userCard->getCard()->getId()] = $userCard;
        }

        // on your own profile you are your own reveal key: everything is in clear
        $visitorCards = $isSelf
            ? $profileCards
            : array_flip($this->userCardRepository->findOwnedCardIds($visitor));

        $universes = [];
        $totals = ['total' => 0, 'common' => 0, 'profileOnly' => 0, 'visitorOnly' => 0, 'missingBoth' => 0, 'mystery' => 0];

        foreach ($this->cardRepository->findPublishedGroupedByExtension() as $group) {
            $cards = [];
            $counts = ['common' => 0, 'profileOnly' => 0, 'visitorOnly' => 0, 'missingBoth' => 0, 'mystery' => 0];

            foreach ($group['cards'] as $card) {
                $cardId = (string) $card->getId();
                $profileCard = $profileCards[$cardId] ?? null;
                $state = $this->resolveState($card, null !== $profileCard, isset($visitorCards[$cardId]), $isSelf);

                $cards[] = new ProfileCardComparison(
                    card: $card,
                    state: $state,
                    profileCard: $state->revealsCard() ? $profileCard : null,
                );

                ++$counts[$this->counterKey($state)];
            }

            $universes[] = new ProfileUniverseComparison(
                extension: $group['extension'],
                cards: $cards,
                total: \count($cards),
                common: $counts['common'],
                profileOnly: $counts['profileOnly'],
                visitorOnly: $counts['visitorOnly'],
                missingBoth: $counts['missingBoth'],
                mysteryCount: $counts['mystery'],
            );

            $totals['total'] += \count($cards);
            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return new ProfileComparison(
            universes: $universes,
            total: $totals['total'],
            common: $totals['common'],
            profileOnly: $totals['profileOnly'],
            visitorOnly: $totals['visitorOnly'],
            missingBoth: $totals['missingBoth'],
            mysteryCount: $totals['mystery'],
            isSelf: $isSelf,
        );
    }

    private function resolveState(Card $card, bool $profileOwns, bool $visitorOwns, bool $isSelf): ProfileCardStateEnum
    {
        if (!$isSelf && $card->isUnique() && !$visitorOwns) {
            return ProfileCardStateEnum::MYSTERY;
        }

        return match (true) {
            $profileOwns && $visitorOwns => ProfileCardStateEnum::COMMON,
            $profileOwns => ProfileCardStateEnum::PROFILE_ONLY,
            $visitorOwns => ProfileCardStateEnum::VISITOR_ONLY,
            default => ProfileCardStateEnum::MISSING_BOTH,
        };
    }

    /**
     * @return 'common'|'profileOnly'|'visitorOnly'|'missingBoth'|'mystery'
     */
    private function counterKey(ProfileCardStateEnum $state): string
    {
        return match ($state) {
            ProfileCardStateEnum::COMMON => 'common',
            ProfileCardStateEnum::PROFILE_ONLY => 'profileOnly',
            ProfileCardStateEnum::VISITOR_ONLY => 'visitorOnly',
            ProfileCardStateEnum::MISSING_BOTH => 'missingBoth',
            ProfileCardStateEnum::MYSTERY => 'mystery',
        };
    }
}
