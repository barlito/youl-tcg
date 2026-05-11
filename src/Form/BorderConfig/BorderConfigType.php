<?php

declare(strict_types=1);

namespace App\Form\BorderConfig;

use App\DTO\BorderConfig\BorderConfigDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BorderConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('colors', CollectionType::class, [
                'label' => 'Couleurs du gradient',
                'entry_type' => ColorType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => [
                        'data-border-preview-target' => 'colorInput',
                        'data-action' => 'input->border-preview#updatePreview',
                    ],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'attr' => ['class' => 'border-colors-collection'],
            ])
            ->add('width', RangeType::class, [
                'label' => 'Épaisseur de la bordure (px)',
                'attr' => [
                    'min' => 1,
                    'max' => 20,
                    'data-border-preview-target' => 'widthInput',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
            ->add('radius', RangeType::class, [
                'label' => 'Rayon des coins (px)',
                'attr' => [
                    'min' => 0,
                    'max' => 50,
                    'data-border-preview-target' => 'radiusInput',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
            ->add('angle', RangeType::class, [
                'label' => 'Angle du gradient (0-360°)',
                'attr' => [
                    'min' => 0,
                    'max' => 360,
                    'data-border-preview-target' => 'angleInput',
                    'data-action' => 'input->border-preview#updatePreview',
                ],
            ])
            ->add('glow', GlowConfigType::class, [
                'label' => 'Effet Glow',
                'required' => false,
            ])
            ->add('fade', FadeConfigType::class, [
                'label' => 'Fade Out',
                'required' => false,
            ])
        ;

        // Import/Export JSON (will be handled by JavaScript)
        if ($options['show_json_import']) {
            $builder
                ->add('toggleJson', ButtonType::class, [
                    'label' => 'Import/Export JSON',
                    'attr' => [
                        'class' => 'btn btn-secondary',
                        'type' => 'button',
                        'data-border-preview-target' => 'jsonToggle',
                        'data-action' => 'click->border-preview#toggleJson',
                    ],
                ])
                ->add('jsonData', TextareaType::class, [
                    'label' => 'Configuration JSON',
                    'required' => false,
                    'mapped' => false,
                    'attr' => [
                        'rows' => 10,
                        'style' => 'display: none;',
                        'placeholder' => 'Collez votre configuration JSON ici...',
                        'data-border-preview-target' => 'jsonTextarea',
                        'data-action' => 'blur->border-preview#importJson',
                    ],
                ])
            ;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BorderConfigDTO::class,
            'show_json_import' => true,
            'attr' => [
                'data-controller' => 'border-preview',
            ],
        ]);
    }
}
