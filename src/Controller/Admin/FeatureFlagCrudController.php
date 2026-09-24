<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FeatureFlag;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Rows come from the migration (one per FeatureEnum case): edit only, no
 * creation nor deletion — a deleted row would just mean OFF.
 *
 * @extends AbstractCrudController<FeatureFlag>
 */
class FeatureFlagCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FeatureFlag::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Fonctionnalité')
            ->setEntityLabelInPlural('Fonctionnalités')
            ->setDefaultSort(['name' => 'ASC'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Coupe ou rouvre une fonctionnalité pour tous les joueurs, immédiatement. Coupée, ses pages répondent 404 et ses liens disparaissent ; les données (offres, historique) sont conservées telles quelles. Voir le guide admin.')
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE, Action::SAVE_AND_ADD_ANOTHER)
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label')->setLabel('Fonctionnalité')->hideOnForm();
        yield TextField::new('name')->setLabel('Code')->hideOnForm();
        yield BooleanField::new('enabled')->setLabel('Active')->renderAsSwitch();
        yield DateTimeField::new('updatedAt')->setLabel('Modifiée le')->hideOnForm();
    }
}
