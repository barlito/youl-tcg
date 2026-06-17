<?php

declare(strict_types=1);

namespace App\Controller\Dev;

use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev-only playground for card visual effects (route /dev/card-effects):
 *  - an interactive demo card driven by a control panel (range sliders for the
 *    --holo-* knobs, selects for the holo preset + rarity, foil/mask toggles)
 *    wired by the card_playground Stimulus controller;
 *  - a gallery rendering every CardEffectEnum preset side by side;
 *  - the five rarity tiers (pure data-rarity defaults).
 *
 * The service only exists in the dev container (#[When]) and the route is
 * declared in config/routes.yaml under when@dev (a #[Route] attribute would
 * be picked up by the route scanner in every environment).
 */
#[When(env: 'dev')]
class CardEffectsDemoController extends AbstractController
{
    public function __invoke(CardRepository $cardRepository): Response
    {
        $referenceCard = $cardRepository->findOneBy(['status' => CardStatusEnum::PUBLISHED])
            ?? throw $this->createNotFoundException('No published card found, load the fixtures first.');

        // A bare transient extension so the showcase shows pure data-rarity
        // defaults, free of any extension-level config.
        $bareExtension = new Extension()->setName('Demo')->setDescription('Demo');

        $playgroundCard = $this->variant($referenceCard, $bareExtension, 'playground')
            ->setRarity(CardRarityEnum::LEGENDARY)
        ;

        $presetDemos = [];
        foreach (CardEffectEnum::cases() as $effect) {
            $presetDemos[] = [
                'card' => $this->variant($referenceCard, $bareExtension, $effect->value)
                    ->setRarity(CardRarityEnum::RARE)
                    ->setVisualConfigOverride(new VisualConfig(holoEffect: $effect)),
                'effect' => $effect,
            ];
        }

        $rarityDemos = [];
        foreach (CardRarityEnum::cases() as $rarity) {
            $rarityDemos[] = [
                'card' => $this->variant($referenceCard, $bareExtension, $rarity->value)
                    ->setRarity($rarity),
                'rarity' => $rarity->value,
            ];
        }

        return $this->render('dev/card_effects.html.twig', [
            'playgroundCard' => $playgroundCard,
            'presetDemos' => $presetDemos,
            'rarityDemos' => $rarityDemos,
            'effects' => CardEffectEnum::cases(),
            'rarities' => CardRarityEnum::cases(),
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
