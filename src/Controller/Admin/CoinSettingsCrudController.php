<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CoinSettings;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * @extends AbstractCrudController<CoinSettings>
 */
class CoinSettingsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CoinSettings::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réglages coin')
            ->setEntityLabelInPlural('Réglages coin')
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE, Action::SAVE_AND_ADD_ANOTHER);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('defaultUniverseRewardCoins')
            ->setLabel('Récompense de complétion par défaut (coins)')
            ->setHelp('Versée aux joueurs qui complètent un univers dont le montant n\'est pas défini sur l\'univers lui-même. 0 : aucune récompense.')
        ;
        yield IntegerField::new('marketFeePercent')
            ->setLabel('Commission du marché (%)')
            ->setHelp('Prélevée par la banque sur chaque vente entre joueurs (arrondie vers le bas) : le vendeur reçoit le prix moins la commission. 0 : aucune commission.')
        ;
    }
}
