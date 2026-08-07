<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One booster slot = one card the booster yields. The form data is the very
 * array stored in Booster::$rarityRates, so no JSON is ever typed by hand:
 * {rarities: {<rarity>: <weight>}, holoChance: <0-100>}.
 *
 * @extends AbstractType<array{rarities: array<string, int>, holoChance: int}>
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
