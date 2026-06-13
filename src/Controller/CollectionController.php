<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Repository\ExtensionRepository;
use App\Repository\UserCardRepository;
use App\Service\Booster\BoosterClaimQuotaInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class CollectionController extends AbstractController
{
    #[Route('/collection', name: 'collection')]
    public function __invoke(
        Request $request,
        #[CurrentUser] DiscordUser $user,
        UserCardRepository $userCardRepository,
        ExtensionRepository $extensionRepository,
        BoosterClaimQuotaInterface $boosterClaimQuota,
    ): Response {
        $extensions = $extensionRepository->findPublishedWithPublishedCardCount();
        $currentExtension = $this->resolveExtensionFilter($request, $extensions);

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
     * Resolves the ?extension= query parameter against the published
     * extensions already loaded: no Doctrine lookup, so a malformed uuid is
     * a plain 404 instead of a conversion error.
     *
     * @param list<array{extension: Extension, cardCount: int}> $extensions
     */
    private function resolveExtensionFilter(Request $request, array $extensions): ?Extension
    {
        $extensionId = $request->query->getString('extension');

        if ('' === $extensionId) {
            return null;
        }

        foreach ($extensions as $item) {
            if ((string) $item['extension']->getId() === $extensionId) {
                return $item['extension'];
            }
        }

        throw $this->createNotFoundException(\sprintf('Unknown extension "%s".', $extensionId));
    }
}
