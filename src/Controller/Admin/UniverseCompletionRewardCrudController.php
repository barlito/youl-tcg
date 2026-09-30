<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\UniverseCompletionReward;
use App\Enum\Coin\UniverseRewardStatusEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * @extends AbstractReadOnlyCrudController<UniverseCompletionReward>
 */
class UniverseCompletionRewardCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return UniverseCompletionReward::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Récompense d\'univers')
            ->setEntityLabelInPlural('Récompenses d\'univers')
            ->setDefaultSort(['completedAt' => 'DESC'])
            ->setSearchFields(['discordUser.username', 'discordUser.discordId', 'extension.name', 'coinTransactionId'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Une ligne par joueur et par univers complété, pour toujours. « Échec » : le coin a refusé (banque vide ?), relancer app:coin:pay-pending-rewards --retry-failed après correction.')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('discordUser', 'Joueur'))
            ->add(EntityFilter::new('extension', 'Univers'))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->statusChoices()))
            ->add(DateTimeFilter::new('completedAt', 'Complété le'))
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('completedAt')->setLabel('Complété le');
        yield TextField::new('discordUser.username')->setLabel('Joueur');
        yield AssociationField::new('extension')->setLabel('Univers');
        yield IntegerField::new('amount')->setLabel('Montant (coins)');
        yield ChoiceField::new('status')
            ->setLabel('Statut')
            ->setChoices($this->statusChoices())
            ->renderAsBadges([
                UniverseRewardStatusEnum::PENDING->value => 'warning',
                UniverseRewardStatusEnum::PAID->value => 'success',
                UniverseRewardStatusEnum::FAILED->value => 'danger',
            ])
        ;
        yield TextField::new('coinTransactionId')->setLabel('Transaction coin');
        yield DateTimeField::new('paidAt')->setLabel('Versée le')->onlyOnDetail();
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('discordUser.discordId')->setLabel('Discord ID')->onlyOnDetail();
    }

    /**
     * @return array<string, string> label => backing value
     */
    private function statusChoices(): array
    {
        $choices = [];
        foreach (UniverseRewardStatusEnum::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }
}
