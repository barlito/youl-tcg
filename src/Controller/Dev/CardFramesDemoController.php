<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev-only preview for the CSS card frame (route /dev/card-frames): the youl
 * frame rendered through the cascade default over a sample of real artworks,
 * plus a name-typography row and a holo layering check.
 *
 * Same gating pattern as CardEffectsDemoController: the service only exists
 * in the dev container (#[When]) and the route is declared under when@dev.
 */
#[When(env: 'dev')]
class CardFramesDemoController extends AbstractController
{
    public function __invoke(CardRepository $cardRepository): Response
    {
        // one published card per extension for artwork variety (light/dark,
        // portrait/landscape subjects), capped to keep the page scannable
        $cards = [];
        foreach ($cardRepository->findBy(['status' => CardStatusEnum::PUBLISHED], ['name' => 'ASC']) as $card) {
            $extensionId = (string) $card->getExtension()?->getId();

            if (isset($cards[$extensionId])) {
                continue;
            }
            $cards[$extensionId] = $card;

            if (\count($cards) >= 8) {
                break;
            }
        }

        if ([] === $cards) {
            throw $this->createNotFoundException('No published card found, load the fixtures first.');
        }

        $cards = array_values($cards);

        // the dev fixtures are all-common: transient clones (never persisted)
        // force one card per rarity so every icon shape is previewable
        $rarityDemos = [];
        foreach (CardRarityEnum::cases() as $rarity) {
            $demo = clone $cards[0];
            $demo->setRarity($rarity);
            $rarityDemos[] = $demo;
        }

        return $this->render('dev/card_frames.html.twig', [
            'cards' => $cards,
            'rarityDemos' => $rarityDemos,
        ]);
    }
}
