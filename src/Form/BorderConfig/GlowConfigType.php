<?php

declare(strict_types=1);

namespace App\Form\BorderConfig;

use App\DTO\BorderConfig\GlowConfigDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GlowConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Activer le glow',
                'required' => false,
                'attr' => [
                    'data-border-preview-target' => 'glowEnabled',
                    'data-action' => 'change->border-preview#updatePreview',
                ],
            ])
            ->add('intensity', RangeType::class, [
                'label' => 'Intensité',
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'data-border-preview-target' => 'glowIntensity',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
            ->add('color', ColorType::class, [
                'label' => 'Couleur du glow',
                'attr' => [
                    'data-border-preview-target' => 'glowColor',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
            ->add('pulse', CheckboxType::class, [
                'label' => 'Animation pulse (respiration)',
                'required' => false,
                'attr' => [
                    'data-border-preview-target' => 'glowPulse',
                    'data-action' => 'change->border-preview#updatePreview',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GlowConfigDTO::class,
        ]);
    }
}
