<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Booster;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One booster slot = one card the booster yields. The form data is the very
 * array stored in Booster::$rarityRates, so no JSON is ever typed by hand:
 * {rarities: {<rarity>: <weight>}, holoChance: <0-100>, uniqueChance: <0-10000>}.
 *
 * @extends AbstractType<array{rarities: array<string, int>, holoChance: int, uniqueChance: int}>
 */
final class BoosterSlotType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rarities', RarityWeightsType::class, [
                // compound forms bubble their errors up by default: keep them
                // on the slot so the admin sees which one is misconfigured
                'error_bubbling' => false,
                'constraints' => [
                    new Assert\Count(min: 1, minMessage: 'Ce slot doit pondérer au moins une rareté.'),
                ],
            ])
            ->add('holoChance', IntegerType::class, [
                'label' => 'Chance holo (%)',
                'required' => false,
                'empty_data' => '0',
                'help' => 'Probabilité que la carte de ce slot sorte en holo.',
                'attr' => ['min' => 0, 'max' => 100],
                'constraints' => [
                    new Assert\NotNull(message: 'Renseigne une chance holo entre 0 et 100.'),
                    new Assert\Range(
                        notInRangeMessage: 'La chance holo doit être comprise entre {{ min }} et {{ max }}.',
                        min: 0,
                        max: 100,
                    ),
                ],
            ])
            ->add('uniqueChance', IntegerType::class, [
                'label' => 'Chance carte unique (pour 10 000)',
                'required' => false,
                'empty_data' => '0',
                'help' => 'Probabilité que ce slot sorte une carte unique 1/1 encore non réclamée, tirée à part du poids par rareté : 25 = 0,25 %. À 0, aucune 1/1 ne peut sortir de ce slot.',
                'attr' => ['min' => 0, 'max' => Booster::UNIQUE_CHANCE_SCALE],
                'constraints' => [
                    new Assert\NotNull(message: 'Renseigne une chance unique entre 0 et ' . Booster::UNIQUE_CHANCE_SCALE . '.'),
                    new Assert\Range(
                        notInRangeMessage: 'La chance unique doit être comprise entre {{ min }} et {{ max }}.',
                        min: 0,
                        max: Booster::UNIQUE_CHANCE_SCALE,
                    ),
                ],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
