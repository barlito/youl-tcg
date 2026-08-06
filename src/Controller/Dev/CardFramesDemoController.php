<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev-only preview for the CSS card frames (route /dev/card-frames):
 * every frame variant of cards/frame.css rendered over a sample of real
 * artworks, plus a holo combo section to check the effect layering and a
 * typography row for the name font.
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

        return $this->render('dev/card_frames.html.twig', [
            'cards' => array_values($cards),
            'variants' => [
                [
                    'code' => 'youl',
                    'label' => 'Youl (référence)',
                    'description' => 'Le rendu cible : nom en haut à gauche, wordmark de l’univers en haut à droite, mat sombre + liseré néon, filigrane YOUL central intégré sous les effets holo.',
                ],
                [
                    'code' => 'plate',
                    'label' => 'Plate',
                    'description' => 'Bandeau nom + lettre de rareté en bas, tampon YOUL en coin, liseré teinté par la rareté (ou --card-border de la config visuelle).',
                ],
                [
                    'code' => 'minimal',
                    'label' => 'Minimal',
                    'description' => 'Simple anneau teinté + nom centré, gros filigrane fantôme au centre de l’artwork.',
                ],
            ],
        ]);
    }
}
