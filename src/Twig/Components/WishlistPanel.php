<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Attribute\RequiresFeature;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\WishlistEntry;
use App\Entity\WishlistUniverse;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Exception\Wishlist\WishlistRefusedException;
use App\Repository\ExtensionRepository;
use App\Service\Wishlist\WishlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * « Ma wishlist »: wishes in clear only for the cards the player can see
 * (the rest stays a card back), watched universes with their missing count.
 */
#[RequiresFeature(FeatureEnum::WISHLIST)]
#[AsLiveComponent]
final class WishlistPanel extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly WishlistService $wishlist,
        private readonly ExtensionRepository $extensionRepository,
    ) {
    }

    /** @return list<array{entry: WishlistEntry, visible: bool, listed: bool}> */
    public function getEntries(): array
    {
        return $this->wishlist->listEntries($this->getDiscordUser());
    }

    /** @return list<array{universe: WishlistUniverse, missing: int}> */
    public function getUniverses(): array
    {
        return $this->wishlist->listUniverses($this->getDiscordUser());
    }

    public function getMax(): int
    {
        return WishlistService::MAX_ENTRIES;
    }

    #[LiveAction]
    public function removeEntry(#[LiveArg] string $entryId): void
    {
        $this->error = null;

        try {
            $this->wishlist->removeEntry($this->getDiscordUser(), $entryId);
        } catch (WishlistRefusedException $exception) {
            $this->error = $exception->getUserMessage();
        }
    }

    #[LiveAction]
    public function unwatch(#[LiveArg] string $slug): void
    {
        $this->error = null;
        $extension = $this->extensionRepository->findOneBy(['slug' => $slug, 'status' => ExtensionStatusEnum::PUBLISHED]);

        if (!$extension instanceof Extension) {
            return;
        }

        try {
            $this->wishlist->unwatchUniverse($this->getDiscordUser(), $extension);
        } catch (WishlistRefusedException $exception) {
            $this->error = $exception->getUserMessage();
        }
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
