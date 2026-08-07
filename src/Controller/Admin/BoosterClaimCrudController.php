<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BoosterClaim;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractReadOnlyCrudController<BoosterClaim>
 */
class BoosterClaimCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return BoosterClaim::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Récupération')
            ->setEntityLabelInPlural('Récupérations quotidiennes')
            ->setDefaultSort(['claimedAt' => 'DESC'])
            ->setSearchFields(['discordUser.username', 'discordUser.discordId', 'booster.name'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Journal des packs gratuits récupérés. Le quota quotidien est un décompte de ces lignes depuis minuit (Europe/Paris).')
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('claimedAt')->setLabel('Récupéré le');
        yield TextField::new('discordUser.username')->setLabel('Joueur');
        yield AssociationField::new('booster')->setLabel('Booster');
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('discordUser.discordId')->setLabel('Discord ID')->onlyOnDetail();
    }
}
