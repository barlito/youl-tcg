<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use App\Entity\Card;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev-only showcase of card visual effects: the same card rendered with
 * different glow colors (--card-glow inherited from a wrapper), 3D tilt and
 * glare on hover. Previews the future extension-level visual config
 * (default glow/borders per extension, overridable per card).
 *
 * The service only exists in the dev container (#[When]) and the route is
 * declared in config/routes.yaml under when@dev (a #[Route] attribute would
 * be picked up by the route scanner in every environment).
 */
#[When(env: 'dev')]
class CardEffectsDemoController extends AbstractController
{
    /**
     * Demo glow palette. The first ten mirror the legacy .card.<type> colors
     * still present in assets/styles/cards/base.css; the last entries show
     * that any color works (the future config will be a free value).
     */
    private const array GLOW_PALETTE = [
        'azur (ex water)' => 'hsl(192, 97%, 60%)',
        'braise (ex fire)' => 'hsl(9, 81%, 59%)',
        'sève (ex grass)' => 'hsl(96, 81%, 65%)',
        'volt (ex lightning)' => 'hsl(54, 87%, 63%)',
        'nébule (ex psychic)' => 'hsl(281, 62%, 58%)',
        'terre (ex fighting)' => 'rgb(145, 90, 39)',
        'abysse (ex darkness)' => 'hsl(189, 77%, 27%)',
        'chrome (ex metal)' => 'hsl(184, 20%, 70%)',
        'or (ex dragon)' => 'hsl(51, 60%, 35%)',
        'rose (ex fairy)' => 'hsl(323, 100%, 89%)',
        'violet arcade' => '#a435f0',
        'magenta arcade' => '#ff3db0',
    ];

    public function __invoke(CardRepository $cardRepository): Response
    {
        $referenceCard = $cardRepository->findOneBy(['status' => CardStatusEnum::PUBLISHED])
            ?? throw $this->createNotFoundException('No published card found, load the fixtures first.');

        $demos = [];

        foreach (self::GLOW_PALETTE as $label => $glow) {
            $demos[] = [
                'card' => $this->variant($referenceCard, $label, $glow),
                'label' => $label,
                'glow' => $glow,
            ];
        }

        return $this->render('dev/card_effects.html.twig', [
            'demos' => $demos,
        ]);
    }

    /**
     * Transient clone, never persisted: same artwork, demo label.
     */
    private function variant(Card $referenceCard, string $label, string $glow): Card
    {
        $variant = clone $referenceCard;
        $variant->setName(ucfirst($label));
        $variant->setDescription(\sprintf('--card-glow: %s', $glow));

        return $variant;
    }
}
