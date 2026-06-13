<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev-only showcase of card visual effects: the same card rendered at the
 * five rarity tiers (glow + holo recipe keyed by data-rarity, see
 * assets/styles/cards/holo.css), plus free-color overrides exercising the
 * real visual-config cascade (Card::visualConfigOverride beating the
 * data-rarity default through CardVisualResolver).
 *
 * The service only exists in the dev container (#[When]) and the route is
 * declared in config/routes.yaml under when@dev (a #[Route] attribute would
 * be picked up by the route scanner in every environment).
 */
#[When(env: 'dev')]
class CardEffectsDemoController extends AbstractController
{
    /**
     * Free glow overrides: any color works (a per-card override beats the
     * extension default and the data-rarity default).
     */
    private const array GLOW_OVERRIDES = [
        'violet arcade' => '#a435f0',
        'magenta arcade' => '#ff3db0',
    ];

    public function __invoke(CardRepository $cardRepository): Response
    {
        $referenceCard = $cardRepository->findOneBy(['status' => CardStatusEnum::PUBLISHED])
            ?? throw $this->createNotFoundException('No published card found, load the fixtures first.');

        // A bare transient extension so the rarity showcase shows pure
        // data-rarity defaults, free of any extension-level glow config.
        $bareExtension = new Extension()->setName('Demo')->setDescription('Demo');

        $rarityDemos = [];
        foreach (CardRarityEnum::cases() as $rarity) {
            $rarityDemos[] = [
                'card' => $this->variant($referenceCard, $bareExtension, $rarity->value)
                    ->setRarity($rarity),
                'rarity' => $rarity->value,
            ];
        }

        $overrideDemos = [];
        foreach (self::GLOW_OVERRIDES as $label => $glow) {
            $overrideDemos[] = [
                'card' => $this->variant($referenceCard, $bareExtension, $label)
                    ->setVisualConfigOverride(new VisualConfig(glow: $glow)),
                'label' => $label,
                'glow' => $glow,
            ];
        }

        return $this->render('dev/card_effects.html.twig', [
            'rarityDemos' => $rarityDemos,
            'overrideDemos' => $overrideDemos,
        ]);
    }

    /**
     * Transient clone, never persisted: same artwork, bare extension, demo label.
     */
    private function variant(Card $referenceCard, Extension $extension, string $label): Card
    {
        $variant = clone $referenceCard;
        $variant->setName(ucfirst($label));
        $variant->setExtension($extension);

        return $variant;
    }
}
