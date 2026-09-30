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
use App\Repository\UserBoosterRepository;
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

#[AsLiveComponent]
final class BoosterHub extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp(writable: true)]
    public string $code = '';

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

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly BoosterRepository $boosterRepository,
        private readonly UserBoosterRepository $userBoosterRepository,
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

        if ('' !== $normalized) {
            $this->code = implode('-', str_split($normalized, 4));
            $this->codePrefilled = true;
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
        return $this->memoize('boosters', $this->loadBoosters(...));
    }

    /**
     * @return list<Booster>
     */
    private function loadBoosters(): array
    {
        $published = $this->getPublishedBoosters();
        $visible = $this->boosterAvailability->filterVisible($published, $this->getInventory());
        $onSale = $this->getShopBoosterIds();

        // a pack on sale is shown even when neither claimable nor owned: it is bought from its tile
        return array_values(array_filter(
            $published,
            static fn (Booster $booster): bool => \in_array($booster, $visible, true) || isset($onSale[(string) $booster->getId()]),
        ));
    }

    public function getRemainingClaims(): int
    {
        return $this->memoize('remainingClaims', fn (): int => $this->boosterClaimService->getRemainingClaims($this->getDiscordUser()));
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
        return $this->memoize('drawable', fn (): array => array_intersect_key($this->getPublishedDrawableIds(), $this->idMap($this->getBoosters())));
    }

    /**
     * Booster ids the "+ Récupérer" button is offered on.
     *
     * @return array<string, true> booster id => true
     */
    public function getRetrievableBoosterIds(): array
    {
        return $this->memoize('retrievable', fn (): array => $this->boosterAvailability->retrievableBoosterIds($this->getBoosters(), $this->getPublishedDrawableIds()));
    }

    /**
     * @return array<string, int> booster id => owned quantity
     */
    public function getInventory(): array
    {
        return $this->memoize('inventory', function (): array {
            $inventory = [];

            foreach ($this->userBoosterRepository->findBy(['discordUser' => $this->getDiscordUser()]) as $userBooster) {
                $inventory[(string) $userBooster->getBooster()->getId()] = $userBooster->getQuantity();
            }

            return $inventory;
        });
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
            $this->memo = []; // memoized reads are stale after a claim
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

        $this->memo = []; // memoized reads are stale after a redemption
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
        return $this->memoize('streak', fn (): OpeningStreak => $this->openingStreakService->getStreak($this->getDiscordUser()));
    }

    /**
     * Streak milestones whose bonus booster is still to pick, oldest first.
     * The hub surfaces the first one; the rest queue up behind it.
     *
     * @return list<StreakReward>
     */
    public function getPendingStreakRewards(): array
    {
        return $this->memoize('pendingRewards', fn (): array => $this->streakRewardService->getPendingRewards($this->getDiscordUser()));
    }

    /**
     * Boosters offered as a streak bonus: the retrievable ones, same rule as
     * the daily claim.
     *
     * @return list<Booster>
     */
    public function getStreakRewardChoices(): array
    {
        return $this->memoize('rewardChoices', fn (): array => $this->boosterAvailability->filterRetrievable($this->getPublishedBoosters(), $this->getPublishedDrawableIds()));
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

        $this->memo = []; // memoized reads are stale after the credit
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
        return $this->memoize('shopBoosters', fn (): array => $this->boosterAvailability->filterPurchasable($this->getPublishedBoosters(), $this->getPublishedDrawableIds()));
    }

    /**
     * @return array<string, true>
     */
    public function getShopBoosterIds(): array
    {
        return $this->memoize('shopIds', function (): array {
            $ids = [];
            foreach ($this->getShopBoosters() as $booster) {
                $ids[(string) $booster->getId()] = true;
            }

            return $ids;
        });
    }

    public function getRemainingPurchases(): int
    {
        return $this->memoize('remainingPurchases', fn (): int => $this->boosterPurchaseService->getRemainingPurchases($this->getDiscordUser()));
    }

    public function getPendingPurchase(): ?BoosterPurchase
    {
        return $this->memoize('pendingPurchase', fn (): ?BoosterPurchase => $this->boosterPurchaseService->getPendingPurchase($this->getDiscordUser()));
    }

    public function getBalance(): ?CoinAmount
    {
        return $this->memoize('balance', fn (): ?CoinAmount => $this->walletBalances->get($this->getDiscordUser()->getDiscordId()));
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

        $this->memo = []; // inventory, balance and quota read before the purchase are stale

        if (!$purchase->isPending()) {
            $this->purchaseSuccess = \sprintf('Un pack « %s » a été ajouté à ton stock !', $booster->getDisplayName());
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

    /**
     * @return list<Booster>
     */
    private function getPublishedBoosters(): array
    {
        return $this->memoize('published', fn (): array => $this->boosterRepository->findPublished());
    }

    /**
     * @return array<string, true>
     */
    private function getPublishedDrawableIds(): array
    {
        return $this->memoize('publishedDrawable', fn (): array => $this->boosterAvailability->drawableBoosterIds($this->getPublishedBoosters()));
    }

    /**
     * @param list<Booster> $boosters
     *
     * @return array<string, true>
     */
    private function idMap(array $boosters): array
    {
        return array_fill_keys(array_map(static fn (Booster $booster): string => (string) $booster->getId(), $boosters), true);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $load
     *
     * @return T
     */
    private function memoize(string $key, \Closure $load): mixed
    {
        if (!\array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $load();
        }

        return $this->memo[$key];
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
