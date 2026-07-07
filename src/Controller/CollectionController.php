<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Repository\CardRepository;
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
    #[Route('/collection/{slug}', name: 'collection', requirements: ['slug' => '[a-z0-9-]+'], defaults: ['slug' => null])]
    public function __invoke(
        Request $request,
        #[CurrentUser] DiscordUser $user,
        UserCardRepository $userCardRepository,
        ExtensionRepository $extensionRepository,
        CardRepository $cardRepository,
        BoosterClaimQuotaInterface $boosterClaimQuota,
        ?string $slug = null,
    ): Response {
        $extensions = $extensionRepository->findPublishedWithPublishedCardCount();

        // Legacy pre-slug urls (/collection?extension=<uuid>): redirect to the slug
        // route instead of silently ignoring the filter; unknown id stays a 404,
        // the contract the query-param version already had.
        $legacyId = $request->query->getString('extension');
        if (null === $slug && '' !== $legacyId) {
            return $this->redirectToRoute(
                'collection',
                ['slug' => $this->resolveLegacyExtensionId($legacyId, $extensions)->getSlug()],
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $currentExtension = $this->resolveExtensionFilter($slug, $extensions);

        $ownedByExtension = $userCardRepository->countOwnedGroupedByExtension($user);
        // fond des tuiles du carrousel quand l'extension n'a pas d'image uploadée
        $coverImages = $cardRepository->findCoverImageNamesByExtension();

        $universes = array_map(static function (array $item) use ($ownedByExtension, $coverImages): array {
            $extensionId = (string) $item['extension']->getId();
            $owned = $ownedByExtension[$extensionId] ?? 0;

            return [
                'extension' => $item['extension'],
                'total' => $item['cardCount'],
                'owned' => $owned,
                'percentage' => $item['cardCount'] > 0 ? (int) round($owned / $item['cardCount'] * 100) : 0,
                'coverImage' => $coverImages[$extensionId] ?? null,
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
     * @param list<array{extension: Extension, cardCount: int}> $extensions
     */
    private function resolveLegacyExtensionId(string $id, array $extensions): Extension
    {
        foreach ($extensions as $item) {
            if (((string) $item['extension']->getId()) === $id) {
                return $item['extension'];
            }
        }

        throw $this->createNotFoundException(\sprintf('Unknown extension "%s".', $id));
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
