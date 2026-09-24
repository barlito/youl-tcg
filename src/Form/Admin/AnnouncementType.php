<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Dto\Admin\AnnouncementDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AnnouncementDraft>
 */
final class AnnouncementType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'help' => 'Court : c\'est la ligne affichée dans la cloche et en tête du toast.',
                'attr' => ['maxlength' => AnnouncementDraft::TITLE_MAX],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'help' => \sprintf('Texte brut, %d caractères max. Pas de HTML ni de Markdown : tout est affiché tel quel.', AnnouncementDraft::MESSAGE_MAX),
                'attr' => ['rows' => 5, 'maxlength' => AnnouncementDraft::MESSAGE_MAX],
            ])
            ->add('link', TextType::class, [
                'label' => 'Lien (optionnel)',
                'required' => false,
                'help' => 'Une page du site uniquement : « /boosters », « /univers/mon-univers » ou une adresse complète du site. Les liens externes sont refusés.',
                'attr' => ['placeholder' => '/boosters'],
            ])
            ->add('target', RecipientTargetType::class, [
                'label' => false,
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AnnouncementDraft::class,
        ]);
    }
}
