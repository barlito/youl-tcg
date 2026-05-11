<?php

declare(strict_types=1);

namespace App\Form\BorderConfig;

use App\DTO\BorderConfig\FadeConfigDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FadeConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Activer le fade out',
                'required' => false,
                'attr' => [
                    'data-border-preview-target' => 'fadeEnabled',
                    'data-action' => 'change->border-preview#updatePreview',
                ],
            ])
            ->add('direction', RangeType::class, [
                'label' => 'Direction du fade (0-360°)',
                'attr' => [
                    'min' => 0,
                    'max' => 360,
                    'data-border-preview-target' => 'fadeDirection',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
            ->add('start', RangeType::class, [
                'label' => 'Position de départ (%)',
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'data-border-preview-target' => 'fadeStart',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
            ->add('length', RangeType::class, [
                'label' => 'Longueur du fade (%)',
                'attr' => [
                    'min' => 10,
                    'max' => 100,
                    'data-border-preview-target' => 'fadeLength',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FadeConfigDTO::class,
        ]);
    }
}
