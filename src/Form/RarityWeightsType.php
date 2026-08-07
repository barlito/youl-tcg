<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\Entity\CardRarityEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The rarity weight map of a single booster slot: one optional integer input
 * per CardRarityEnum tier. An empty input means "this rarity cannot come out
 * of this slot", so the stored map only keeps the weights that were filled in.
 *
 * @extends AbstractType<array<string, int>>
 */
final class RarityWeightsType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (CardRarityEnum::ascending() as $rarity) {
            $builder->add($rarity->value, IntegerType::class, [
                'label' => $rarity->label(),
                'required' => false,
                'attr' => ['min' => 1, 'placeholder' => '—'],
                'constraints' => [
                    new Assert\Positive(message: 'Le poids doit être un entier positif (laisse vide pour exclure la rareté de ce slot).'),
                ],
            ]);
        }

        $builder->addModelTransformer(new CallbackTransformer(
            $this->toFormWeights(...),
            $this->toStoredWeights(...),
        ));
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'label' => 'Poids par rareté',
            'help' => 'Poids relatifs, pas des pourcentages : 60/30/10 et 6/3/1 donnent les mêmes taux.',
        ]);
    }

    /**
     * Stored map -> form: every tier gets an input, absent ones stay blank.
     *
     * @return array<string, int|null>
     */
    private function toFormWeights(mixed $storedWeights): array
    {
        $storedWeights = \is_array($storedWeights) ? $storedWeights : [];
        $formWeights = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            $weight = $storedWeights[$rarity->value] ?? null;
            $formWeights[$rarity->value] = is_numeric($weight) ? (int) $weight : null;
        }

        return $formWeights;
    }

    /**
     * Form -> stored map: blank (and non-positive) inputs are dropped, the
     * remaining weights keep the enum order so the JSON stays stable.
     * A non-positive input is still reported by the Positive constraint.
     *
     * @return array<string, int>
     */
    private function toStoredWeights(mixed $formWeights): array
    {
        $formWeights = \is_array($formWeights) ? $formWeights : [];
        $storedWeights = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            $weight = $formWeights[$rarity->value] ?? null;

            if (is_numeric($weight) && (int) $weight > 0) {
                $storedWeights[$rarity->value] = (int) $weight;
            }
        }

        return $storedWeights;
    }
}
