<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BoosterCodeRedemption;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractReadOnlyCrudController<BoosterCodeRedemption>
 */
class BoosterCodeRedemptionCrudController extends AbstractReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return BoosterCodeRedemption::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisation de code')
            ->setEntityLabelInPlural('Utilisations de codes')
            ->setDefaultSort(['redeemedAt' => 'DESC'])
            ->setSearchFields(['discordUser.username', 'discordUser.discordId', 'boosterCode.code', 'boosterCode.batchLabel'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Qui a utilisé quel code, et combien de packs ont été crédités.')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('boosterCode');
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('redeemedAt')->setLabel('Utilisé le');
        yield TextField::new('discordUser.username')->setLabel('Joueur');
        yield AssociationField::new('boosterCode')->setLabel('Code');
        yield IntegerField::new('quantity')->setLabel('Packs crédités');
        yield TextField::new('discordUser.discordId')->setLabel('Discord ID')->onlyOnDetail();
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
    }
}
