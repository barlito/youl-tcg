<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Dto\Admin\RecipientTarget;
use App\Entity\DiscordUser;
use App\Enum\Notification\NotificationTargetEnum;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Tous les joueurs" or a selection (EasyAdmin's local autocomplete widget).
 * The recipients block is only shown for a selection (recipient-target.js).
 *
 * @extends AbstractType<RecipientTarget>
 */
final class RecipientTargetType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $modes = $options['allow_none']
            ? NotificationTargetEnum::cases()
            : [NotificationTargetEnum::ALL, NotificationTargetEnum::SELECTION];

        $builder
            ->add('mode', EnumType::class, [
                'class' => NotificationTargetEnum::class,
                'choices' => $modes,
                'choice_label' => static fn (NotificationTargetEnum $mode): string => $mode->label(),
                'expanded' => true,
                'label' => $options['mode_label'],
                'attr' => ['data-recipient-target-mode' => ''],
            ])
            ->add('recipients', EntityType::class, [
                'class' => DiscordUser::class,
                'multiple' => true,
                'required' => false,
                'label' => 'Joueurs',
                'choice_label' => static fn (DiscordUser $user): string => \sprintf('%s (%s)', $user->getUsername(), $user->getDiscordId()),
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('user')->orderBy('user.username', 'ASC'),
                'help' => 'Tape un pseudo ou un identifiant Discord.',
                'attr' => [
                    'data-ea-widget' => 'ea-autocomplete',
                    'data-ea-i18n-no-results-found' => 'Aucun joueur trouvé',
                ],
                'row_attr' => ['data-recipient-target-recipients' => ''],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecipientTarget::class,
            'empty_data' => static fn (): RecipientTarget => new RecipientTarget(),
            'allow_none' => false,
            'mode_label' => 'Destinataires',
        ]);
        $resolver->setAllowedTypes('allow_none', 'bool');
        $resolver->setAllowedTypes('mode_label', 'string');
    }
}
