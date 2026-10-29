<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\OpeningStreak;
use App\Entity\Booster;
use App\Entity\BoosterPurchase;
use App\Entity\DiscordUser;
use App\Entity\StreakReward;
use App\Enum\Booster\BoosterCodeRefusalEnum;
use App\Exception\Booster\BoosterCodeRefusedException;
use App\Exception\Booster\BoosterException;
use App\Repository\BoosterRepository;
use App\Repository\ExtensionRepository;
use App\Repository\UserBoosterRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Service\Booster\BoosterClaimService;
use App\Service\Booster\BoosterCodeGenerator;
use App\Service\Booster\BoosterCodeRedeemService;
use App\Service\Booster\BoosterPurchaseService;
use App\Service\Booster\OpeningStreakService;
use App\Service\Booster\StreakRewardService;
use App\Service\Coin\CoinAmount;
use App\Service\Coin\WalletBalances;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

#[AsLiveComponent]
final class BoosterHub extends AbstractController
{
    use DefaultActionTrait;

    public const string FILTER_ALL = 'tous';

    public const string FILTER_INCOMPLETE = 'incomplets';

    public const string FILTER_PAID = 'payants';

    public const string SORT_COMPLETION = 'completion';

    public const string SORT_NAME = 'az';

    #[LiveProp]
    public ?string $error = null;

    /** Packs grid filter (tous|incomplets|payants), mirrored in the url as ?filtre=. */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'filtre'))]
    public string $filter = self::FILTER_ALL;

    /** Packs grid order (completion|az), mirrored in the url as ?tri=. */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'tri'))]
    public string $sort = self::SORT_COMPLETION;

    /**
     * Variant picked on each universe tile (Standard / Boosted…), kept across re-renders.
     *
     * @var array<string, string> extension id => booster id
     */
    #[LiveProp]
    public array $variants = [];

    #[LiveProp(writable: true)]
    public string $code = '';

    /** Echo of the link's ?code=, nulled once redeemed so a reload does not pre-fill it again. */
    #[LiveProp(url: new UrlMapping(as: 'code'))]
    public ?string $codeLink = null;

    /** The code came from a notification link (?code=): highlight the field. */
    #[LiveProp]
    public bool $codePrefilled = false;

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

    /** Booster awaiting the second click of the purchase confirmation. */
    #[LiveProp]
    public ?string $confirmingPurchaseBoosterId = null;

    #[LiveProp]
    public ?string $purchaseError = null;

    #[LiveProp]
    public ?string $purchaseSuccess = null;

    private ?BoosterPurchase $pendingPurchase = null;

    private bool $pendingPurchaseLoaded = false;

    private ?CoinAmount $balance = null;

    private bool $balanceLoaded = false;

    /**
     * Inventory is read twice per render (hero total + packs grid): memoize
     * the query for the lifetime of the (per-request) component instance.
     *
     * @var array<string, int>|null
     */
    private ?array $inventory = null;

    /** @var list<Booster>|null */
    private ?array $boosters = null;

    /** @var array<string, array{owned: int, total: int, percentage: int}>|null */
    private ?array $completion = null;

    public function __construct(
        private readonly BoosterRepository $boosterRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly ExtensionRepository $extensionRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly BoosterAvailabilityService $boosterAvailability,
        private readonly BoosterClaimService $boosterClaimService,
        private readonly BoosterCodeRedeemService $boosterCodeRedeemService,
        private readonly RateLimiterFactoryInterface $boosterCodeRedeemLimiter,
        private readonly OpeningStreakService $openingStreakService,
        private readonly StreakRewardService $streakRewardService,
        private readonly BoosterPurchaseService $boosterPurchaseService,
        private readonly WalletBalances $walletBalances,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Pre-fills "J'ai un code" from a notification link (/boosters?code=...).
     * Normalized like a typed code and never submitted: the player validates
     * it, through the same rate-limited action.
     */
    public function mount(?string $code = null): void
    {
        $normalized = BoosterCodeGenerator::normalize((string) $code);

        // anything that cannot be a code (?code=<script>…) is ignored, and dropped from the url
        if (BoosterCodeGenerator::looksLikeCode($normalized)) {
            $this->code = implode('-', str_split($normalized, 4));
            $this->codePrefilled = true;
            $this->codeLink = $code;
        }
    }

    /**
     * The hub list: the published boosters the user is allowed to see
     * (claimable, or event/code ones they already own copies of).
     *
     * @return list<Booster>
     */
    public function getBoosters(): array
    {
        if (null !== $this->boosters) {
            return $this->boosters;
        }

        $published = $this->boosterRepository->findPublished();
        $visible = $this->boosterAvailability->filterVisible($published, $this->getInventory());
        $onSale = $this->getShopBoosterIds();

        // a pack on sale is shown even when neither claimable nor owned: it is bought from its tile
        return $this->boosters = array_values(array_filter(
            $published,
            static fn (Booster $booster): bool => \in_array($booster, $visible, true) || isset($onSale[(string) $booster->getId()]),
        ));
    }

    public function getActiveFilter(): string
    {
        return \in_array($this->filter, [self::FILTER_INCOMPLETE, self::FILTER_PAID], true) ? $this->filter : self::FILTER_ALL;
    }

    #[LiveAction]
    public function filterPacks(#[LiveArg] string $filter): void
    {
        $this->filter = \in_array($filter, [self::FILTER_INCOMPLETE, self::FILTER_PAID], true) ? $filter : self::FILTER_ALL;
    }

    public function getActiveSort(): string
    {
        return self::SORT_NAME === $this->sort ? self::SORT_NAME : self::SORT_COMPLETION;
    }

    #[LiveAction]
    public function sortPacks(#[LiveArg] string $sort): void
    {
        $this->sort = self::SORT_NAME === $sort ? self::SORT_NAME : self::SORT_COMPLETION;
    }

    /**
     * The grid once the « Tous / Incomplets / Payants » filter is applied.
     *
     * @return list<Booster>
     */
    public function getFilteredBoosters(): array
    {
        return $this->filterBoosters($this->getActiveFilter());
    }

    /**
     * @return array<string, int> filter => packs it keeps
     */
    public function getFilterCounts(): array
    {
        return [
            self::FILTER_ALL => \count($this->getBoosters()),
            self::FILTER_INCOMPLETE => \count($this->filterBoosters(self::FILTER_INCOMPLETE)),
            self::FILTER_PAID => \count($this->filterBoosters(self::FILTER_PAID)),
        ];
    }

    /**
     * Owned packs that can be opened, for the « Prêts à ouvrir » row.
     *
     * @return list<Booster>
     */
    public function getReadyBoosters(): array
    {
        $inventory = $this->getInventory();
        $drawable = $this->getDrawableBoosterIds();

        return array_values(array_filter(
            $this->getBoosters(),
            static fn (Booster $booster): bool => ($inventory[(string) $booster->getId()] ?? 0) > 0 && isset($drawable[(string) $booster->getId()]),
        ));
    }

    /**
     * The filtered packs as one tile per universe, its variants (Standard, Boosted…) inside,
     * in the chosen order: completion = unfinished universes closest to completion first, finished ones last.
     *
     * @return list<array{extensionId: string, name: string, boosters: list<Booster>, selected: Booster, completion: array{owned: int, total: int, percentage: int}|null}>
     */
    public function getPackGroups(): array
    {
        $completion = $this->getCompletionByExtension();
        $groups = [];

        foreach ($this->getFilteredBoosters() as $booster) {
            $extensionId = (string) $booster->getExtension()->getId();
            $groups[$extensionId] ??= [
                'extensionId' => $extensionId,
                'name' => (string) $booster->getExtension()->getName(),
                'boosters' => [],
                'completion' => $completion[$extensionId] ?? null,
            ];
            $groups[$extensionId]['boosters'][] = $booster;
        }

        $result = [];
        foreach ($groups as $extensionId => $group) {
            $result[] = $group + ['selected' => $this->selectedVariant($extensionId, $group['boosters'])];
        }

        $sort = $this->getActiveSort();
        usort($result, static function (array $a, array $b) use ($sort): int {
            $percentA = $a['completion']['percentage'] ?? -1;
            $percentB = $b['completion']['percentage'] ?? -1;
            $doneA = 100 === $percentA;
            $doneB = 100 === $percentB;

            if (self::SORT_NAME === $sort) {
                return strnatcasecmp($a['name'], $b['name']);
            }

            return $doneA <=> $doneB ?: $percentB <=> $percentA ?: strnatcasecmp($a['name'], $b['name']);
        });

        return $result;
    }

    #[LiveAction]
    public function pickVariant(#[LiveArg] string $extensionId, #[LiveArg] string $boosterId): void
    {
        foreach ($this->getBoosters() as $booster) {
            if ((string) $booster->getId() === $boosterId && (string) $booster->getExtension()->getId() === $extensionId) {
                $this->variants[$extensionId] = $boosterId;

                return;
            }
        }
    }

    /**
     * Completion of each published universe (same rule as /univers), from two grouped queries.
     *
     * @return array<string, array{owned: int, total: int, percentage: int}> extension id => completion
     */
    public function getCompletionByExtension(): array
    {
        if (null !== $this->completion) {
            return $this->completion;
        }

        $owned = $this->userCardRepository->countOwnedGroupedByExtension($this->getDiscordUser());
        $completion = [];
        foreach ($this->extensionRepository->findPublishedWithPublishedCardCount() as $item) {
            $id = (string) $item['extension']->getId();
            $total = $item['cardCount'];
            if ($total > 0) {
                $count = min($owned[$id] ?? 0, $total);
                $completion[$id] = ['owned' => $count, 'total' => $total, 'percentage' => (int) floor($count / $total * 100)];
            }
        }

        return $this->completion = $completion;
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
        return $this->boosterClaimService->getSecondsUntilReset();
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
     * Booster ids the "+ Récupérer" button is offered on.
     *
     * @return array<string, true> booster id => true
     */
    public function getRetrievableBoosterIds(): array
    {
        return $this->boosterAvailability->retrievableBoosterIds($this->getBoosters());
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
            $this->boosters = null;
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
        $this->codePrefilled = false;

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
            // UNKNOWN also covers codes reserved for another player
            if (BoosterCodeRefusalEnum::UNKNOWN === $exception->getReason()) {
                $limiter->consume();
            }

            $this->codeError = $exception->getUserMessage();

            return;
        }

        $this->inventory = null; // the memoized inventory is stale after a redemption
        $this->boosters = null;
        $this->code = '';
        $this->codeLink = null;
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
     * Boosters offered as a streak bonus: the retrievable ones, same rule as
     * the daily claim.
     *
     * @return list<Booster>
     */
    public function getStreakRewardChoices(): array
    {
        return $this->boosterAvailability->filterRetrievable($this->boosterRepository->findPublished());
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
        $this->boosters = null;
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
     * @return list<Booster>
     */
    public function getShopBoosters(): array
    {
        return $this->boosterAvailability->filterPurchasable($this->boosterRepository->findPublished());
    }

    /**
     * @return array<string, true>
     */
    public function getShopBoosterIds(): array
    {
        $ids = [];
        foreach ($this->getShopBoosters() as $booster) {
            $ids[(string) $booster->getId()] = true;
        }

        return $ids;
    }

    public function getRemainingPurchases(): int
    {
        return $this->boosterPurchaseService->getRemainingPurchases($this->getDiscordUser());
    }

    public function getPendingPurchase(): ?BoosterPurchase
    {
        if (!$this->pendingPurchaseLoaded) {
            $this->pendingPurchase = $this->boosterPurchaseService->getPendingPurchase($this->getDiscordUser());
            $this->pendingPurchaseLoaded = true;
        }

        return $this->pendingPurchase;
    }

    public function getBalance(): ?CoinAmount
    {
        if (!$this->balanceLoaded) {
            $this->balance = $this->walletBalances->get($this->getDiscordUser()->getDiscordId());
            $this->balanceLoaded = true;
        }

        return $this->balance;
    }

    public function getPurchaseBlock(Booster $booster): ?string
    {
        $balance = $this->getBalance();

        return match (true) {
            $this->getPendingPurchase() instanceof BoosterPurchase => 'Paiement en cours de vérification',
            $this->getRemainingPurchases() < 1 => 'Déjà acheté aujourd\'hui',
            !$balance instanceof CoinAmount => 'Youl Coin indisponible',
            $balance->isLessThan(CoinAmount::fromCoins((int) $booster->getPurchasePrice())) => 'Solde insuffisant',
            default => null,
        };
    }

    #[LiveAction]
    public function askPurchase(#[LiveArg] string $boosterId): void
    {
        $this->purchaseError = null;
        $this->purchaseSuccess = null;

        $booster = $this->findBooster($boosterId);

        if (!$booster instanceof Booster || !$this->boosterAvailability->isPurchasable($booster)) {
            $this->purchaseError = 'Ce pack n\'est pas en vente pour le moment.';

            return;
        }

        $this->confirmingPurchaseBoosterId = $this->getPurchaseBlock($booster) ? null : $boosterId;
    }

    #[LiveAction]
    public function cancelPurchase(): void
    {
        $this->confirmingPurchaseBoosterId = null;
    }

    #[LiveAction]
    public function confirmPurchase(#[LiveArg] string $boosterId): void
    {
        $this->purchaseError = null;
        $this->purchaseSuccess = null;

        $confirmed = $this->confirmingPurchaseBoosterId === $boosterId;
        $this->confirmingPurchaseBoosterId = null;
        $booster = $this->findBooster($boosterId);
        $playerToken = $this->requestStack->getCurrentRequest()?->cookies->get('jwt');

        if (!$confirmed || !$booster instanceof Booster || !\is_string($playerToken)) {
            $this->purchaseError = 'Achat impossible, recharge la page et réessaie.';

            return;
        }

        try {
            $purchase = $this->boosterPurchaseService->purchase($this->getDiscordUser(), $booster, $playerToken);
        } catch (BoosterException $exception) {
            $this->purchaseError = $exception->getUserMessage();

            return;
        }

        // inventory and balance memoized before the purchase are stale
        $this->inventory = null;
        $this->boosters = null;
        $this->balanceLoaded = false;
        $this->pendingPurchaseLoaded = false;

        if (!$purchase->isPending()) {
            $this->purchaseSuccess = \sprintf('Un pack « %s » a été ajouté à ton stock !', $booster->getDisplayName());
        }
    }

    /**
     * @return list<Booster>
     */
    private function filterBoosters(string $filter): array
    {
        $completion = $this->getCompletionByExtension();
        $onSale = $this->getShopBoosterIds();

        return array_values(array_filter($this->getBoosters(), static function (Booster $booster) use ($filter, $completion, $onSale): bool {
            $universe = $completion[(string) $booster->getExtension()->getId()] ?? null;

            return match ($filter) {
                self::FILTER_INCOMPLETE => null !== $universe && $universe['owned'] < $universe['total'],
                self::FILTER_PAID => isset($onSale[(string) $booster->getId()]),
                default => true,
            };
        }));
    }

    /**
     * The picked variant if still in the tile, else the first claimable one (the free pack), else the first.
     *
     * @param non-empty-list<Booster> $boosters
     */
    private function selectedVariant(string $extensionId, array $boosters): Booster
    {
        foreach ($boosters as $booster) {
            if ((string) $booster->getId() === ($this->variants[$extensionId] ?? null)) {
                return $booster;
            }
        }

        foreach ($boosters as $booster) {
            if ($booster->isClaimable()) {
                return $booster;
            }
        }

        return $boosters[0];
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
