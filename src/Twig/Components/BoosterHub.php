<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\OpeningStreak;
use App\Entity\Booster;
use App\Entity\DiscordUser;
use App\Entity\StreakReward;
use App\Enum\Booster\BoosterCodeRefusalEnum;
use App\Exception\Booster\BoosterCodeRefusedException;
use App\Exception\Booster\BoosterException;
use App\Repository\BoosterRepository;
use App\Repository\UserBoosterRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Booster\BoosterClaimService;
use App\Service\Booster\BoosterCodeRedeemService;
use App\Service\Booster\OpeningStreakService;
use App\Service\Booster\StreakRewardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
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

    #[LiveProp(writable: true)]
    public string $code = '';

    #[LiveProp]
    public ?string $codeError = null;

    #[LiveProp]
    public ?string $codeSuccess = null;

    #[LiveProp]
    public ?string $streakError = null;

    #[LiveProp]
    public ?string $streakSuccess = null;

    /** Pack picked in the streak reward selector (its id). */
    #[LiveProp(writable: true)]
    public string $streakRewardBoosterId = '';

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
        private readonly BoosterCodeRedeemService $boosterCodeRedeemService,
        private readonly RateLimiterFactoryInterface $boosterCodeRedeemLimiter,
        private readonly OpeningStreakService $openingStreakService,
        private readonly StreakRewardService $streakRewardService,
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
     * Redeems an event/giveaway code.
     *
     * Only unknown codes are charged to the player's attempt budget: they are
     * the sole answer that tells a stranger anything (this code exists, that
     * one does not). Every other refusal — expired, already redeemed,
     * exhausted, pack not out yet — proves the player was given a real code,
     * and a successful redemption costs nothing at all. Someone redeeming
     * thirty event codes in a row is never throttled.
     */
    #[LiveAction]
    public function redeemCode(): void
    {
        $this->codeError = null;
        $this->codeSuccess = null;

        $user = $this->getDiscordUser();
        $limiter = $this->boosterCodeRedeemLimiter->create($user->getDiscordId());

        // Peek without spending: charging happens below, but a player who
        // already burnt the budget must not keep probing for free.
        // NB: consume(0) reports accepted whatever the state — the remaining
        // token count is the only reliable read.
        $limit = $limiter->consume(0);

        if ($limit->getRemainingTokens() < 1) {
            $this->codeError = \sprintf(
                'Trop de tentatives. Réessaie dans %d minute(s).',
                max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60)),
            );

            return;
        }

        try {
            $redemption = $this->boosterCodeRedeemService->redeem($user, $this->code);
        } catch (BoosterCodeRefusedException $exception) {
            if (BoosterCodeRefusalEnum::UNKNOWN === $exception->getReason()) {
                $limiter->consume();
            }

            $this->codeError = $exception->getUserMessage();

            return;
        }

        $this->inventory = null; // the memoized inventory is stale after a redemption
        $this->code = '';
        $this->codeSuccess = \sprintf(
            '%d pack%s « %s » ajouté%s à ton stock !',
            $redemption->getQuantity(),
            $redemption->getQuantity() > 1 ? 's' : '',
            $redemption->getBoosterCode()->getBooster()->getDisplayName(),
            $redemption->getQuantity() > 1 ? 's' : '',
        );
    }

    public function getStreak(): OpeningStreak
    {
        return $this->openingStreakService->getStreak($this->getDiscordUser());
    }

    /**
     * Streak milestones whose bonus booster is still to pick, oldest first.
     * The hub surfaces the first one; the rest queue up behind it.
     *
     * @return list<StreakReward>
     */
    public function getPendingStreakRewards(): array
    {
        return $this->streakRewardService->getPendingRewards($this->getDiscordUser());
    }

    /**
     * Boosters offered as a streak bonus: the claimable ones, same visibility
     * rule as the daily claim (findPublished already scopes to published
     * extensions).
     *
     * @return list<Booster>
     */
    public function getStreakRewardChoices(): array
    {
        return array_values(array_filter(
            $this->boosterRepository->findPublished(),
            $this->boosterAvailability->isClaimable(...),
        ));
    }

    /**
     * Spends a streak reward on the chosen booster. The real guards live in
     * StreakRewardService (FOR UPDATE on the reward row, claimable booster):
     * a forged live action gets a clean French refusal.
     */
    #[LiveAction]
    public function chooseStreakReward(#[LiveArg] string $rewardId): void
    {
        $this->streakError = null;
        $this->streakSuccess = null;

        $booster = $this->findBooster($this->streakRewardBoosterId);

        if (!$booster instanceof Booster) {
            $this->streakError = 'Choisis un pack avant de valider.';

            return;
        }

        if (!Uuid::isValid($rewardId)) {
            $this->streakError = 'Cette récompense n\'est plus disponible.';

            return;
        }

        try {
            $reward = $this->streakRewardService->chooseBooster($this->getDiscordUser(), $rewardId, $booster);
        } catch (BoosterException $exception) {
            $this->streakError = $exception->getUserMessage();

            return;
        }

        $this->inventory = null; // the memoized inventory is stale after the credit
        $this->streakRewardBoosterId = '';
        $this->streakSuccess = \sprintf(
            'Palier %d jours : un pack « %s » ajouté à ton stock !',
            $reward->getMilestone(),
            $booster->getDisplayName(),
        );

        // the banner immediately shows the next milestone: say so, or spending
        // a reward looks like the click did nothing
        $remaining = \count($this->getPendingStreakRewards());

        if ($remaining > 0) {
            $this->streakSuccess .= \sprintf(
                ' Il te reste %d récompense%s à récupérer.',
                $remaining,
                $remaining > 1 ? 's' : '',
            );
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
