<?php

declare(strict_types=1);

namespace App\Form;

use App\Repository\CardRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Multi-select of card tags (EasyAdmin TomSelect): existing tags are suggested, new ones are typed in.
 *
 * @extends AbstractType<list<string>>
 */
final class CardTagsType extends AbstractType
{
    public function __construct(private readonly CardRepository $cardRepository)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'multiple' => true,
            'expanded' => false,
            'required' => false,
            // lazy option: one loader (and one tag query) per form
            'choice_loader' => fn (Options $options): CardTagChoiceLoader => new CardTagChoiceLoader($this->cardRepository->findAllTags()),
            'attr' => [
                'data-ea-widget' => 'ea-autocomplete',
                'data-ea-autocomplete-allow-item-create' => 'true',
            ],
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
