<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Repository\ExtensionRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterClaimQuotaInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class CollectionController extends AbstractController
{
    #[Route('/collection/{slug}', name: 'collection', defaults: ['slug' => null], requirements: ['slug' => '[a-z0-9-]+'])]
    public function __invoke(
        #[CurrentUser] DiscordUser $user,
        UserCardRepository $userCardRepository,
        ExtensionRepository $extensionRepository,
        BoosterClaimQuotaInterface $boosterClaimQuota,
        ?string $slug = null,
    ): Response {
        $extensions = $extensionRepository->findPublishedWithPublishedCardCount();
        $currentExtension = $this->resolveExtensionFilter($slug, $extensions);

        $ownedByExtension = $userCardRepository->countOwnedGroupedByExtension($user);

        $universes = array_map(static function (array $item) use ($ownedByExtension): array {
            $owned = $ownedByExtension[(string) $item['extension']->getId()] ?? 0;

            return [
                'extension' => $item['extension'],
                'total' => $item['cardCount'],
                'owned' => $owned,
                'percentage' => $item['cardCount'] > 0 ? (int) round($owned / $item['cardCount'] * 100) : 0,
            ];
        }, $extensions);

        $totalPublished = array_sum(array_column($extensions, 'cardCount'));
        $ownedTotalDistinct = array_sum($ownedByExtension);

        return $this->render('pages/collection.html.twig', [
            'universes' => $universes,
            'currentExtension' => $currentExtension,
            'userCards' => $userCardRepository->findOwnedWithCards($user, $currentExtension),
            'ownedTotalDistinct' => $ownedTotalDistinct,
            'ownedTotalQuantity' => $userCardRepository->sumOwnedQuantities($user),
            'totalPublished' => $totalPublished,
            'completionPct' => $totalPublished > 0 ? (int) round($ownedTotalDistinct / $totalPublished * 100) : 0,
            'remainingClaims' => $boosterClaimQuota->getRemainingClaims($user),
            'dailyLimit' => BoosterClaimQuotaInterface::DAILY_LIMIT,
        ]);
    }

    /**
     * Resolves the {slug} route parameter against the published extensions already
     * loaded: no extra Doctrine lookup, and an unknown slug is a plain 404.
     *
     * @param list<array{extension: Extension, cardCount: int}> $extensions
     */
    private function resolveExtensionFilter(?string $slug, array $extensions): ?Extension
    {
        if (null === $slug || '' === $slug) {
            return null;
        }

        foreach ($extensions as $item) {
            if ($item['extension']->getSlug() === $slug) {
                return $item['extension'];
            }
        }

        throw $this->createNotFoundException(\sprintf('Unknown extension "%s".', $slug));
    }
}
