<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BoosterPurchase;
use App\Enum\Booster\BoosterPurchaseStatusEnum;
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
 * @extends AbstractReadOnlyCrudController<BoosterPurchase>
 */
class BoosterPurchaseCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return BoosterPurchase::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Achat')
            ->setEntityLabelInPlural('Achats de boosters')
            ->setDefaultSort(['requestedAt' => 'DESC'])
            ->setSearchFields(['discordUser.username', 'discordUser.discordId', 'booster.name', 'coinTransactionId'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Boosters achetés en Youl Coin. « En vérification » : le paiement n\'a pas encore pu être confirmé auprès du coin, la commande app:coin:reconcile-purchases le résout.')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('discordUser', 'Joueur'))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->statusChoices()))
            ->add(DateTimeFilter::new('requestedAt', 'Demandé le'))
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('requestedAt')->setLabel('Demandé le');
        yield TextField::new('discordUser.username')->setLabel('Joueur');
        yield AssociationField::new('booster')->setLabel('Booster');
        yield IntegerField::new('price')->setLabel('Prix (coins)');
        yield ChoiceField::new('status')
            ->setLabel('Statut')
            ->setChoices($this->statusChoices())
            ->renderAsBadges([
                BoosterPurchaseStatusEnum::PENDING->value => 'warning',
                BoosterPurchaseStatusEnum::COMPLETED->value => 'success',
                BoosterPurchaseStatusEnum::FAILED->value => 'danger',
            ])
        ;
        yield TextField::new('coinTransactionId')->setLabel('Transaction coin');
        yield DateTimeField::new('resolvedAt')->setLabel('Résolu le')->onlyOnDetail();
        yield TextField::new('failureReason')->setLabel('Motif d\'échec')->onlyOnDetail();
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('discordUser.discordId')->setLabel('Discord ID')->onlyOnDetail();
    }

    /**
     * @return array<string, string> label => backing value
     */
    private function statusChoices(): array
    {
        $choices = [];
        foreach (BoosterPurchaseStatusEnum::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }
}
